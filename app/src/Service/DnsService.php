<?php

namespace App\Service;

class DnsService
{
    /**
     * Whether the domain has at least one NS, MX, A or AAAA record, i.e.
     * whether it (still) exists in the DNS.
     */
    public function domainExists(string $domain): bool
    {
        // The trailing dot prevents the resolver from appending a search domain.
        $fqdn = rtrim($domain, '.') . '.';

        foreach (['NS', 'MX', 'A', 'AAAA'] as $type) {
            if (checkdnsrr($fqdn, $type)) {
                return true;
            }
        }

        return false;
    }
}
