<?php
declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

final class AiOutboundPolicy
{
    /** @param callable(string): array<int, string>|null $resolver */
    public function __construct(private $resolver = null)
    {
        $this->resolver ??= static function (string $host): array {
            $records = [];
            foreach ((array) @dns_get_record($host, DNS_A | DNS_AAAA) as $record) {
                $address = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($address)) $records[] = $address;
            }
            return array_values(array_unique($records));
        };
    }

    /** @return array{url:string,host:string,authority:string,ip:string,port:int} */
    public function resolve(string $url): array
    {
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('地址格式无效');
        }
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = trim(strtolower((string) ($parts['host'] ?? '')), '[]');
        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('仅允许无凭证的 http/https 地址');
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if ($port < 1 || $port > 65535) throw new InvalidArgumentException('端口无效');
        if ($this->isForbiddenHost($host)) throw new InvalidArgumentException('不允许访问内网地址');
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($this->resolver)($host);
        if (! is_array($addresses) || $addresses === []) throw new InvalidArgumentException('主机解析失败');
        $allowed = [];
        foreach ($addresses as $address) {
            if (! is_string($address) || ! filter_var($address, FILTER_VALIDATE_IP) || $this->isForbiddenIp($address)) {
                throw new InvalidArgumentException('不允许访问内网/保留地址');
            }
            $allowed[] = $address;
        }
        $ip = $allowed[0];
        $authority = str_contains($host, ':') ? "[{$host}]:{$port}" : "{$host}:{$port}";
        $connectHost = str_contains($ip, ':') ? "[{$ip}]" : $ip;
        $path = $parts['path'] ?? '/';
        if ($path === '') $path = '/';
        if (isset($parts['query'])) $path .= '?' . $parts['query'];
        return ['url' => "{$scheme}://{$connectHost}:{$port}{$path}", 'host' => $host, 'authority' => $authority, 'ip' => $ip, 'port' => $port];
    }

    private function isForbiddenHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) return false;
        return str_ends_with($host, '.') || ! str_contains($host, '.')
            || preg_match('/^[0-9.]+$/', $host) === 1 || str_starts_with($host, '0x')
            || ! preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]*$/', $host)
            || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal');
    }

    private function isForbiddenIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ranges = ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8',
                '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24',
                '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24',
                '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4'];
        } else {
            // Only native global unicast; exclude transition/mapped, documentation and special-use ranges.
            if (! $this->inRange($ip, '2000::/3')) return true;
            $ranges = ['2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20'];
        }
        foreach ($ranges as $range) if ($this->inRange($ip, $range)) return true;
        return false;
    }

    private function inRange(string $ip, string $cidr): bool
    {
        [$base, $prefix] = explode('/', $cidr);
        $address = inet_pton($ip);
        $network = inet_pton($base);
        if ($address === false || $network === false || strlen($address) !== strlen($network)) return false;
        $bytes = intdiv((int) $prefix, 8);
        $bits = (int) $prefix % 8;
        return substr($address, 0, $bytes) === substr($network, 0, $bytes)
            && ($bits === 0 || (ord($address[$bytes]) & (0xff << (8 - $bits))) === (ord($network[$bytes]) & (0xff << (8 - $bits))));
    }
}
