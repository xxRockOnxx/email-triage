/**
 * Single source of truth for urgency -> visual treatment mapping, so the
 * spine color, badge, and label stay consistent across Emails/Index,
 * Emails/Show, and Dashboard without re-deriving the mapping each place.
 */
const URGENCY_CONFIG = {
    low: {
        label: "Low",
        spineClass: "urgency-spine--low",
        badgeClass: "bg-urgency-low-bg text-urgency-low",
    },
    medium: {
        label: "Medium",
        spineClass: "urgency-spine--medium",
        badgeClass: "bg-urgency-medium-bg text-urgency-medium",
    },
    high: {
        label: "High",
        spineClass: "urgency-spine--high",
        badgeClass: "bg-urgency-high-bg text-urgency-high",
    },
    critical: {
        label: "Critical",
        spineClass: "urgency-spine--critical",
        badgeClass: "bg-urgency-critical-bg text-urgency-critical",
    },
} as const;

export function useUrgency() {
    function config(urgency: keyof typeof URGENCY_CONFIG) {
        return URGENCY_CONFIG[urgency] ?? URGENCY_CONFIG.low;
    }

    return { config, URGENCY_CONFIG };
}
