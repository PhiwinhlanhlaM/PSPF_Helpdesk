<?php
if (!defined('TICKET_GAUGE_INCLUDED')) {
    define('TICKET_GAUGE_INCLUDED', true);

    // Returns attributes to attach to a ticket row for the JS gauge
    function ticket_row_attrs(array $ticket): string
    {
        $created = $ticket['query_date'] ?? '';
        $ts = $created ? strtotime($created) : 0;
        $priority = $ticket['priority'] ?? '';

        $attrs = [];
        $attrs[] = 'class="ticket-row"';
        $attrs[] = 'data-created-ts="' . ((int)$ts) . '"';
        $attrs[] = 'data-priority="' . htmlspecialchars($priority, ENT_QUOTES) . '"';

        return implode(' ', $attrs);
    }

    // Echo CSS + JS assets. Call this from inside the <head> of pages.
    function ticket_gauge_assets(): void
    {
        ?>
        <style>
        .ticket-gauge-tooltip {
            position: absolute;
            z-index: 2000;
            min-width: 160px;
            background: #fff;
            border: 1px solid rgba(0,0,0,0.12);
            box-shadow: 0 6px 18px rgba(0,0,0,0.08);
            padding: 10px;
            border-radius: 8px;
            display: flex;
            gap: 10px;
            align-items: center;
            pointer-events: none;
            font-size: 13px;
        }
        .ticket-gauge-ring {
            width: 56px;
            height: 56px;
            display: inline-block;
            position: relative;
        }
        .ticket-gauge-ring svg { width:100%; height:100%; transform: rotate(-90deg); }
        .ticket-gauge-ring .gauge-label { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; font-weight:600; font-size:12px; }
        .ticket-gauge-text { line-height:1.1; }
        .ticket-gauge-text .muted { color: #6c757d; font-size:12px; }
        </style>

        <script>
        (function(){
            const thresholds = { 'High': 24, 'Medium': 72, 'Low': 120 };

            function formatRemaining(ms) {
                if (ms <= 0) return 'Escalated';
                const totalSec = Math.floor(ms / 1000);
                const hours = Math.floor(totalSec / 3600);
                const mins = Math.floor((totalSec % 3600) / 60);
                return `${hours}h ${String(mins).padStart(2,'0')}m`;
            }

            function createTooltip() {
                const el = document.createElement('div');
                el.className = 'ticket-gauge-tooltip';
                el.innerHTML = `
                    <div class="ticket-gauge-ring" aria-hidden>
                        <svg viewBox="0 0 36 36" class="gauge-svg">
                            <path class="gauge-bg" d="M18 2.0845a15.9155 15.9155 0 1 1 0 31.831a15.9155 15.9155 0 1 1 0-31.831" fill="none" stroke="#eee" stroke-width="3.2"></path>
                            <path class="gauge-fg" d="M18 2.0845a15.9155 15.9155 0 1 1 0 31.831a15.9155 15.9155 0 1 1 0-31.831" fill="none" stroke="#0d6efd" stroke-width="3.2" stroke-dasharray="0,100"></path>
                        </svg>
                        <div class="gauge-label">0%</div>
                    </div>
                    <div class="ticket-gauge-text">
                        <div class="remaining">Remaining: <span class="remaining-val muted">-</span></div>
                        <div class="priority muted">Priority: -</div>
                    </div>
                `;
                return el;
            }

            let tooltip = null;

            function showFor(target, ev) {
                const createdTs = Number(target.dataset.createdTs) * 1000;
                const priority = target.dataset.priority || 'Low';
                const thresholdHours = thresholds[priority] ?? 120;
                const totalMs = thresholdHours * 3600 * 1000;
                const elapsed = Date.now() - createdTs;
                const remaining = Math.max(0, totalMs - elapsed);
                const pct = Math.max(0, Math.min(100, Math.round((remaining / totalMs) * 100)));

                if (!tooltip) tooltip = createTooltip();
                const fg = tooltip.querySelector('.gauge-fg');
                const label = tooltip.querySelector('.gauge-label');
                const remVal = tooltip.querySelector('.remaining-val');
                const pr = tooltip.querySelector('.priority');

                // stroke-dasharray uses 100 as circumference proxy
                fg.setAttribute('stroke-dasharray', `${pct},100`);
                label.textContent = pct + '%';
                remVal.textContent = formatRemaining(remaining);
                pr.textContent = 'Priority: ' + priority;

                document.body.appendChild(tooltip);
                positionTooltip(ev);
            }

            function positionTooltip(ev) {
                if (!tooltip) return;
                const pad = 12;
                let x = (ev.clientX || (ev.touches && ev.touches[0].clientX)) + pad;
                let y = (ev.clientY || (ev.touches && ev.touches[0].clientY)) + pad;
                const rect = tooltip.getBoundingClientRect();
                if (x + rect.width > window.innerWidth) x = window.innerWidth - rect.width - pad;
                if (y + rect.height > window.innerHeight) y = window.innerHeight - rect.height - pad;
                tooltip.style.left = x + 'px';
                tooltip.style.top = y + 'px';
            }

            function hideTooltip() {
                if (tooltip && tooltip.parentNode) tooltip.parentNode.removeChild(tooltip);
                tooltip = null;
            }

            document.addEventListener('mouseover', function(e){
                const tr = e.target.closest('tr[data-created-ts]');
                if (!tr) return;
                showFor(tr, e);
            });

            document.addEventListener('mousemove', function(e){
                if (!tooltip) return;
                positionTooltip(e);
            });

            document.addEventListener('mouseout', function(e){
                const tr = e.target.closest('tr[data-created-ts]');
                if (!tr) return;
                hideTooltip();
            });

        })();
        </script>
        <?php
    }
}

?>
