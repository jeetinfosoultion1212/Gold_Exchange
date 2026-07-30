/**
 * Gold rate helpers — display unit may be per gram or per 10g; calculations use per-gram internally.
 * Configure via window.GOLD_RATE_CONFIG from PHP before loading this script.
 */
(function (global) {
    const cfg = global.GOLD_RATE_CONFIG || { unit: 'gram', divisor: 1, label: '₹/g', suffix: '/g' };

    const GoldRateUtils = {
        unit: cfg.unit || 'gram',
        divisor: parseFloat(cfg.divisor) || 1,
        label: cfg.label || '₹/g',
        suffix: cfg.suffix || '/g',

        effectivePerGram(displayRate) {
            const d = parseFloat(displayRate) || 0;
            return this.divisor > 0 ? d / this.divisor : d;
        },

        calcAmount(weight, displayRate) {
            const w = parseFloat(weight) || 0;
            return w * this.effectivePerGram(displayRate);
        },

        formatRateText(displayRate, decimals = 2) {
            const n = parseFloat(displayRate) || 0;
            return '₹' + n.toLocaleString('en-IN', {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals
            }) + this.suffix;
        }
    };

    global.GoldRateUtils = GoldRateUtils;
})(typeof window !== 'undefined' ? window : globalThis);
