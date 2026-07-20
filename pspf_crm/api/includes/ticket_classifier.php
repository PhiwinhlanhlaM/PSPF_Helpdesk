<?php
// includes/ticket_classifier.php
//
// Rule-based ticket classifier. Maps a ticket's free text (title + description,
// with an optional nudge from its query type) to a single coarse subject-matter
// category. Runs entirely in PHP with no external service, so it works on the
// CRM's internal, SMTP-only network and adds no per-ticket latency or cost.
//
// How it scores:
//   - Each category owns a list of keyword/phrase rules.
//   - A rule matches on a WHOLE-WORD, case-insensitive basis (so "vpn" does not
//     fire on "vpns" is fine, but "leave" does not fire inside "sleeve").
//   - The category with the most matched rules wins. Ties are broken by the
//     order categories are declared below (earlier = higher priority), which
//     puts the more specific/security-relevant buckets ahead of generic ones.
//   - No matches at all -> "General".
//
// Keep the category list and CATEGORY order stable: the stored tickets.category
// values and the daily department digest both read these labels.

if (!function_exists('ticketCategories')) {
    /**
     * The canonical, ordered list of category labels.
     * @return array<int,string>
     */
    function ticketCategories(): array
    {
        return array_keys(ticketCategoryRules() + ['General' => []]);
    }
}

if (!function_exists('ticketCategoryRules')) {
    /**
     * category label => list of keyword/phrase rules.
     * Declaration order is the tie-break priority (earlier wins).
     * @return array<string, array<int,string>>
     */
    function ticketCategoryRules(): array
    {
        return [
            // Security-sensitive access issues first so they never get buried.
            'Access & Accounts' => [
                'password', 'reset password', 'forgot password', 'log in', 'login',
                'sign in', 'signin', 'log on', 'account', 'locked out', 'locked',
                'unlock', 'access', 'permission', 'permissions', 'credential',
                'credentials', 'username', 'user name', 'mfa', 'otp', '2fa',
                'authenticate', 'authentication', 'disabled account', 'new user',
                'onboard access',
            ],
            'Email & Communication' => [
                'email', 'e-mail', 'mailbox', 'outlook', 'mail', 'smtp', 'inbox',
                'distribution list', 'calendar', 'meeting invite', 'teams',
                'zoom', 'signature', 'spam', 'phishing', 'undeliverable',
            ],
            'Network & Connectivity' => [
                'network', 'wifi', 'wi-fi', 'wireless', 'internet', 'vpn',
                'connection', 'connectivity', 'cannot connect', 'no connection',
                'lan', 'ethernet', 'offline', 'server down', 'dns', 'ip address',
                'firewall', 'proxy', 'bandwidth', 'timeout', 'time out',
            ],
            'Hardware & Devices' => [
                'laptop', 'desktop', 'computer', 'pc', 'printer', 'printing',
                'print', 'monitor', 'screen', 'keyboard', 'mouse', 'scanner',
                'toner', 'cartridge', 'hardware', 'device', 'battery', 'charger',
                'docking', 'dock', 'usb', 'hard drive', 'ram', 'projector',
                'headset', 'webcam',
            ],
            'Software & Applications' => [
                'software', 'application', 'app', 'install', 'installation',
                'reinstall', 'license', 'licence', 'update', 'upgrade', 'patch',
                'microsoft', 'office', 'excel', 'word', 'powerpoint', 'crash',
                'crashing', 'error message', 'not responding', 'freezing',
                'bug', 'system error', 'module', 'browser', 'antivirus',
            ],
            'Finance & Payroll' => [
                'payroll', 'salary', 'wage', 'payment', 'invoice', 'finance',
                'financial', 'claim', 'reimburse', 'reimbursement', 'allowance',
                'pension', 'contribution', 'contributions', 'benefit',
                'benefits', 'refund', 'deduction', 'tax', 'budget', 'procurement',
                'purchase order',
            ],
            'HR & Personnel' => [
                'leave', 'annual leave', 'sick leave', 'human resource',
                'human resources', 'hr', 'recruitment', 'recruit', 'onboarding',
                'employee', 'staff', 'attendance', 'appraisal', 'performance review',
                'contract', 'resignation', 'transfer', 'promotion', 'timesheet',
            ],
            'Facilities & Vehicles' => [
                'vehicle', 'car', 'fleet', 'fuel', 'driver', 'transport',
                'booking', 'air conditioner', 'aircon', 'air conditioning',
                'building', 'maintenance', 'cleaning', 'furniture', 'facility',
                'facilities', 'office space', 'plumbing', 'electricity', 'generator',
                'keys', 'parking',
            ],
            'Data & Reporting' => [
                'report', 'reporting', 'statistics', 'dashboard', 'export',
                'record', 'records', 'database', 'data entry', 'backup',
                'restore', 'spreadsheet', 'analytics', 'query results',
            ],
        ];
    }
}

if (!function_exists('classifyTicket')) {
    /**
     * Classify a ticket's text into one category label.
     *
     * @param string $title       Ticket title.
     * @param string $description Ticket description.
     * @param string $extra       Optional extra hint text (e.g. member type,
     *                             source) mixed in with lower weight.
     * @return string One of ticketCategories(); "General" when nothing matches.
     */
    function classifyTicket(string $title, string $description, string $extra = ''): string
    {
        // Title is the strongest signal, so weight a title hit more than a body
        // hit by repeating the title in the haystack.
        $haystack = strtolower($title . ' ' . $title . ' ' . $description . ' ' . $extra);

        // Normalise punctuation to spaces so whole-word boundaries are reliable.
        $normalized = ' ' . preg_replace('/[^a-z0-9]+/', ' ', $haystack) . ' ';

        $best      = 'General';
        $bestScore = 0;

        foreach (ticketCategoryRules() as $category => $keywords) {
            $score = 0;
            foreach ($keywords as $kw) {
                $needle = trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($kw)));
                if ($needle === '') {
                    continue;
                }
                // Count whole-token occurrences of the (possibly multi-word) needle.
                $score += preg_match_all('/(?<=\s)' . preg_quote($needle, '/') . '(?=\s)/', $normalized);
            }
            // Strictly-greater keeps declaration order as the tie-break, so an
            // earlier (more specific) category wins a tie.
            if ($score > $bestScore) {
                $bestScore = $score;
                $best      = $category;
            }
        }

        return $best;
    }
}
