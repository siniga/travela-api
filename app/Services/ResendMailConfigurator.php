<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Resend;

class ResendMailConfigurator
{
    /**
     * Pick a Resend-verified sender address for the configured from local-part.
     */
    public function resolvedFromAddress(): string
    {
        $configured = (string) config('mail.from.address', '');
        $localPart = $this->localPart($configured);
        $verifiedDomains = $this->verifiedDomainNames();

        if ($verifiedDomains === []) {
            return $configured !== '' ? $configured : $this->defaultFromAddress();
        }

        $configuredDomain = $this->domainPart($configured);
        if ($configuredDomain !== '' && in_array($configuredDomain, $verifiedDomains, true)) {
            return $localPart.'@'.$configuredDomain;
        }

        $preferredDomain = (string) config('services.resend.domain', '');
        if ($preferredDomain !== '' && in_array($preferredDomain, $verifiedDomains, true)) {
            return $localPart.'@'.$preferredDomain;
        }

        $fallbackDomain = $verifiedDomains[0];

        if ($configuredDomain !== '' && $configuredDomain !== $fallbackDomain) {
            Log::warning('Resend sender domain adjusted to a verified domain', [
                'configured_from' => $configured,
                'using_from' => $localPart.'@'.$fallbackDomain,
                'verified_domains' => $verifiedDomains,
            ]);
        }

        return $localPart.'@'.$fallbackDomain;
    }

    /**
     * @return list<string>
     */
    public function verifiedDomainNames(): array
    {
        $key = (string) config('services.resend.key', '');
        if ($key === '') {
            return [];
        }

        return $this->rememberVerifiedDomains(function () use ($key) {
            try {
                $collection = Resend::client($key)->domains->list();
                $domains = [];

                foreach ($collection->data ?? [] as $domain) {
                    $name = (string) ($domain->name ?? '');
                    $status = strtolower((string) ($domain->status ?? ''));

                    if ($name !== '' && $status === 'verified') {
                        $domains[] = $name;
                    }
                }

                sort($domains);

                return $domains;
            } catch (\Throwable $e) {
                Log::error('Could not load verified Resend domains', [
                    'error' => $e->getMessage(),
                ]);

                return [];
            }
        });
    }

    /**
     * @param  callable(): list<string>  $resolver
     * @return list<string>
     */
    private function rememberVerifiedDomains(callable $resolver): array
    {
        try {
            return Cache::remember('resend:verified_domains', 300, $resolver);
        } catch (\Throwable $e) {
            Log::warning('Resend domain cache unavailable; querying Resend directly', [
                'error' => $e->getMessage(),
            ]);

            return $resolver();
        }
    }

    public function defaultFromAddress(): string
    {
        $domain = (string) config('services.resend.domain', 'thetravela.com');
        $local = (string) config('services.resend.from_local', 'noreply');

        return $local.'@'.$domain;
    }

    private function localPart(string $address): string
    {
        if (! str_contains($address, '@')) {
            return 'noreply';
        }

        return strstr($address, '@', true) ?: 'noreply';
    }

    private function domainPart(string $address): string
    {
        if (! str_contains($address, '@')) {
            return '';
        }

        return substr(strrchr($address, '@'), 1) ?: '';
    }
}
