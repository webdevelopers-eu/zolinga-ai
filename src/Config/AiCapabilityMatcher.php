<?php

declare(strict_types=1);

namespace Zolinga\AI\Config;

/**
 * Shared capability matching utility used by both backend selection
 * and instruction selection.
 *
 * Capabilities can be exact strings or patterns with wildcards (*).
 * Matching is bidirectional — either side may contain wildcards.
 * A higher specificity score means more non-wildcard characters matched.
 *
 * Example:
 *   AiCapabilityMatcher::match(['translate:en-cs', 'search:images'], ['default', 'search:*', 'translate:en-*']) => int
 *   AiCapabilityMatcher::match(['default', 'voice'], ['default', 'search:*']) => false (missing 'voice')
 *
 * @author Daniel Sevcik <sevcik@webdevelopers.eu>
 * @date 2026-06-22
 */
class AiCapabilityMatcher
{
    /**
     * Score how well $have (one configured capability) satisfies $want (one required capability).
     *
     * Bidirectional fnmatch — either the requirement or the configured capability
     * may contain wildcards. Returns 0 if no match, or a specificity score
     * (non-wildcard character count in the concatenation of both strings).
     *
     * @param string $want One required capability (may contain wildcards).
     * @param string $have One configured capability (may contain wildcards).
     * @return int 0 = no match, >0 = specificity score.
     */
    private static function scorePair(string $want, string $have): int
    {
        if (fnmatch($want, $have) || fnmatch($have, $want)) {
            $nonWildcard = preg_replace('/[*?]|\[[^\]]*\]/', '', $have . $want);
            return strlen($nonWildcard);
        }
        return 0;
    }

    /**
     * For a set of required capabilities, return false if any is unsatisfied,
     * otherwise return the summed specificity score.
     *
     * @param string|array $want Required capability or array of required capabilities.
     * @param array $have Configured capabilities to match against.
     * @return false|int false: no match, int: summed specificity score (higher = more specific).
     */
    public static function match(string|array $want, array $have): false|int
    {
        $required = is_string($want) ? [$want] : $want;
        $score = 0;

        foreach ($required as $cap) {
            $bestScore = 0;
            foreach ($have as $configured) {
                $bestScore = max($bestScore, self::scorePair($cap, $configured));
            }
            if ($bestScore === 0) {
                return false;
            }
            $score += $bestScore;
        }
        return $score;
    }
}