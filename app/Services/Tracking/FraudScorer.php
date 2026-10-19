<?php

namespace App\Services\Tracking;

use App\Models\IpBlock;
use App\Models\Visit;

class FraudScorer
{
    /** @var list<string> */
    private array $datacenterCidrs = [
        '3.0.0.0/8', '13.32.0.0/15', '15.230.0.0/16', '18.32.0.0/11', '34.64.0.0/10',
        '35.184.0.0/13', '52.0.0.0/10', '54.0.0.0/8', '104.196.0.0/14',
        '20.33.0.0/16', '20.36.0.0/14', '40.64.0.0/10', '52.224.0.0/11',
        '104.40.0.0/13', '157.55.0.0/16', '168.61.0.0/16',
        '142.250.0.0/15', '172.217.0.0/16', '216.58.192.0/19',
        '138.68.0.0/16', '159.65.0.0/16', '167.99.0.0/16', '134.122.0.0/16',
        '51.38.0.0/16', '51.68.0.0/16', '137.74.0.0/16', '147.135.0.0/16',
        '5.9.0.0/16', '78.46.0.0/15', '88.198.0.0/16', '116.202.0.0/15', '136.243.0.0/16',
    ];

    /**
     * @return array{score: int, flagged: bool, block: bool, reason: ?string}
     */
    public function score(Visit $visit): array
    {
        $reasons = [];
        $score = 0;
        $block = false;

        if ($this->isBot((string) $visit->user_agent)) {
            $score += 90;
            $block = true;
            $reasons[] = 'Known bot user agent';
        }

        if ($this->isDatacenter($visit->ip_address)) {
            $score += 80;
            $block = true;
            $reasons[] = 'Datacenter IP';
        }

        $burst = Visit::query()
            ->where('track_id', $visit->track_id)
            ->where('ip_address', $visit->ip_address)
            ->where('landed_at', '>=', now()->subMinutes(10))
            ->count();
        if ($burst >= 5) {
            $score += 70;
            $block = true;
            $reasons[] = 'Repeated clicks on this Track in a short window';
        }

        $recent = Visit::query()
            ->where('track_id', $visit->track_id)
            ->where('ip_address', $visit->ip_address)
            ->where('landed_at', '>=', now()->subDay())
            ->get(['time_on_page_seconds', 'converted']);
        $shortUnconverted = $recent->filter(
            fn (Visit $row): bool => $row->time_on_page_seconds < 5 && ! $row->converted,
        )->count();
        $conversions = $recent->where('converted', true)->count();
        if ($shortUnconverted >= 4 && $conversions === 0) {
            $score += 70;
            $block = true;
            $reasons[] = 'Several short sessions from this IP with no conversion';
        }

        $flagOnly = false;
        if ($recent->count() >= 6 && $recent->avg('time_on_page_seconds') < 10) {
            $score += 40;
            $flagOnly = true;
        }

        if ($this->isWhitelisted($visit)) {
            return [
                'score' => min(100, $score),
                'flagged' => false,
                'block' => false,
                'reason' => null,
            ];
        }

        if ($block) {
            return [
                'score' => min(100, $score),
                'flagged' => true,
                'block' => true,
                'reason' => implode('; ', $reasons),
            ];
        }

        if ($flagOnly) {
            return [
                'score' => min(100, $score),
                'flagged' => true,
                'block' => false,
                'reason' => 'Very low time on page with high volume from this IP',
            ];
        }

        return ['score' => min(100, $score), 'flagged' => false, 'block' => false, 'reason' => null];
    }

    public function isBot(string $userAgent): bool
    {
        $ua = strtolower($userAgent);
        if ($ua === '') {
            return false;
        }

        foreach ([
            'bot', 'spider', 'crawl', 'headless', 'phantomjs', 'slurp', 'wget', 'curl',
            'python-requests', 'scrapy', 'httpclient', 'go-http-client', 'semrush', 'ahrefs',
            'petalbot', 'bytespider', 'gptbot', 'claudebot',
        ] as $needle) {
            if (str_contains($ua, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function isDatacenter(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
        foreach ($this->datacenterCidrs as $cidr) {
            if ($this->inCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    public function inCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, '32');
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }
        $mask = -1 << (32 - (int) $bits);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    private function isWhitelisted(Visit $visit): bool
    {
        return IpBlock::query()
            ->where('track_id', $visit->track_id)
            ->where('ip_address', $visit->ip_address)
            ->where('status', 'whitelisted')
            ->exists();
    }
}
