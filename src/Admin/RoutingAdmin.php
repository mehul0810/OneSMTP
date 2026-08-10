<?php

declare(strict_types=1);

namespace OneSMTP\Admin;

use InvalidArgumentException;
use OneSMTP\Audit\AdminAuditLogger;
use OneSMTP\Core\Capabilities;
use OneSMTP\Dispatch\RoutingRuleNormalizer;
use OneSMTP\Dispatch\RoutingRulesRepository;
use OneSMTP\Product\FeatureGate;
use OneSMTP\Repository\ProviderRepository;
use RuntimeException;

final class RoutingAdmin
{
    private const ACTION_NAME = 'onesmtp_routing_action';
    private const NONCE_NAME = 'onesmtp_routing_nonce';

    public function __construct(
        private ?RoutingRulesRepository $rules = null,
        private ?ProviderRepository $providers = null,
        private ?FeatureGate $featureGate = null,
        private ?AdminRequest $request = null,
        private ?AdminAuditLogger $auditLogger = null
    ) {
        $this->rules = $rules ?? new RoutingRulesRepository();
        $this->providers = $providers ?? new ProviderRepository();
        $this->featureGate = $featureGate ?? new FeatureGate();
        $this->request = $request ?? new AdminRequest();
        $this->auditLogger = $auditLogger ?? new AdminAuditLogger();
    }

    public function handleRequest(): void
    {
        if ( ! in_array(($GLOBALS['pagenow'] ?? ''), ['admin.php', 'options-general.php'], true)) {
            return;
        }

        if ($this->request->method() !== 'POST') {
            return;
        }

        $action = $this->request->postAction(self::ACTION_NAME);
        if ( ! in_array($action, ['save', 'delete'], true)) {
            return;
        }

        if ( ! Capabilities::canManage()) {
            wp_die(
                esc_html__('You are not allowed to manage Aculect Mail routing rules.', 'onesmtp'),
                esc_html__('Aculect Mail access denied', 'onesmtp'),
                ['response' => 403]
            );
        }

        check_admin_referer(self::ACTION_NAME, self::NONCE_NAME);

        if ( ! $this->featureGate->isEnabled(FeatureGate::SMART_ROUTING)) {
            $this->redirect('upgrade_required');
        }

        try {
            if ($action === 'delete') {
                $ruleId = isset($_POST['rule_id']) ? absint($_POST['rule_id']) : 0;
                $rule = $this->findRule($ruleId);
                $deleted = $this->rules->delete($ruleId);
                if ($deleted && $rule !== null) {
                    $this->auditLogger->logRoutingRuleChange(
                        'deleted',
                        $ruleId,
                        (int) ($rule['provider_id'] ?? 0),
                        (int) ($rule['priority'] ?? 100),
                        count( (array) ($rule['conditions'] ?? [])),
                        ! empty($rule['enabled'])
                    );
                }
                $this->redirect($deleted ? 'deleted' : 'failure');
            }

            $condition = [
                'field' => isset($_POST['condition_field']) ? wp_unslash( (string) $_POST['condition_field']) : '',
                'operator' => isset($_POST['condition_operator']) ? wp_unslash( (string) $_POST['condition_operator']) : '',
                'value' => isset($_POST['condition_value']) ? wp_unslash( (string) $_POST['condition_value']) : '',
            ];
            $providerId = isset($_POST['provider_id']) ? absint($_POST['provider_id']) : 0;
            if ( ! $this->hasActiveProvider($providerId)) {
                throw new InvalidArgumentException('Choose an active provider for the routing rule.');
            }
            $saved = $this->rules->add([
                'provider_id' => $providerId,
                'priority' => isset($_POST['priority']) ? absint($_POST['priority']) : 100,
                'enabled' => isset($_POST['enabled']),
                'conditions' => [$condition],
            ]);

            if ($saved) {
                $storedRules = $this->rules->get();
                $storedRule = $storedRules[ array_key_last($storedRules) ] ?? [];
                $this->auditLogger->logRoutingRuleChange(
                    'created',
                    (int) ($storedRule['id'] ?? 0),
                    $providerId,
                    (int) ($_POST['priority'] ?? 100),
                    1,
                    isset($_POST['enabled'])
                );
            }

            $this->redirect($saved ? 'saved' : 'failure');
        } catch (InvalidArgumentException $exception) {
            $this->redirect('invalid', $exception->getMessage());
        }
    }

    /**
     * @param array<int,array<string,mixed>> $activeProviders
     */
    public function render(array $activeProviders = []): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect status is sanitized and only changes the local notice.
        $status = isset($_GET['onesmtp_routing_status'])
            ? sanitize_key(wp_unslash( (string) $_GET['onesmtp_routing_status']))
            : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect status is sanitized and only changes the local notice.
        $message = isset($_GET['onesmtp_routing_message'])
            ? sanitize_text_field(wp_unslash( (string) $_GET['onesmtp_routing_message']))
            : '';
        $this->renderStatus($status, $message);

        echo '<section class="onesmtp-settings-panel onesmtp-settings-panel--full onesmtp-routing-rules-panel postbox">';
        echo '<div class="postbox-header"><h3 class="hndle">' . esc_html__('Conditional routing rules', 'onesmtp') . '</h3></div><div class="inside">';

        if ( ! $this->featureGate->isEnabled(FeatureGate::SMART_ROUTING)) {
            echo '<p class="description onesmtp-settings-panel-description">' . esc_html__('Sender, recipient, subject, content, and source conditions are available with Pro. Core priority, weight, health, failover, queues, and logs remain unchanged.', 'onesmtp') . '</p>';
            echo '<div class="onesmtp-routing-gated-state"><span class="onesmtp-status-pill is-pending">' . esc_html__('Available with Pro', 'onesmtp') . '</span><button type="button" class="button button-secondary" disabled aria-disabled="true">' . esc_html__('Requires Pro', 'onesmtp') . '</button></div>';
            echo '</div></section>';

            return;
        }

        echo '<p class="description onesmtp-settings-panel-description">' . esc_html__('Rules are evaluated in priority order. Matching is case-insensitive, does not execute regular expressions, and never writes message content or routing context to logs.', 'onesmtp') . '</p>';
        /* translators: 1: maximum message characters inspected, 2: maximum configured condition characters. */
        echo '<p class="description">' . esc_html(sprintf(__('Content is evaluated only in memory (first %1$d characters); each condition value is limited to %2$d characters.', 'onesmtp'), RoutingRuleNormalizer::MAX_MATCH_LENGTH, RoutingRuleNormalizer::MAX_VALUE_LENGTH)) . '</p>';

        $rules = $this->rules->get();
        if ($rules === []) {
            echo '<div class="notice notice-info inline"><p>' . esc_html__('No conditional routing rules are configured. Add a rule below or continue using the default healthy-provider route.', 'onesmtp') . '</p></div>';
        } else {
            $this->renderRules($rules, $activeProviders);
        }

        if ($activeProviders === []) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('Connect an active provider before adding a conditional routing rule.', 'onesmtp') . '</p></div>';
        } else {
            $this->renderAddForm($activeProviders);
        }

        echo '</div></section>';
    }

    /**
     * @param array<int,array<string,mixed>> $rules
     * @param array<int,array<string,mixed>> $activeProviders
     */
    private function renderRules(array $rules, array $activeProviders): void
    {
        $providerNames = [];
        foreach ($activeProviders as $provider) {
            $providerNames[ (int) ($provider['id'] ?? 0) ] = (string) ($provider['name'] ?? __('Provider', 'onesmtp'));
        }

        echo '<div class="onesmtp-routing-table-wrap"><table class="widefat striped"><thead><tr><th scope="col">' . esc_html__('Priority', 'onesmtp') . '</th><th scope="col">' . esc_html__('Provider', 'onesmtp') . '</th><th scope="col">' . esc_html__('Condition', 'onesmtp') . '</th><th scope="col">' . esc_html__('Status', 'onesmtp') . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__('Actions', 'onesmtp') . '</span></th></tr></thead><tbody>';
        foreach ($rules as $rule) {
            $condition = is_array($rule['conditions'][0] ?? null) ? $rule['conditions'][0] : [];
            $field = (string) ($condition['field'] ?? '');
            $operator = (string) ($condition['operator'] ?? 'equals');
            $providerId = (int) ($rule['provider_id'] ?? 0);
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field returns the complete nonce input markup.
            echo '<tr><td>' . esc_html( (string) ( (int) ($rule['priority'] ?? 100))) . '</td><td>' . esc_html($providerNames[ $providerId ] ?? __('Unavailable provider', 'onesmtp')) . '</td><td>' . esc_html($this->fieldLabel($field) . ' — ' . $this->operatorLabel($operator)) . '<br><span class="description">' . esc_html__('Configured value is hidden on this screen.', 'onesmtp') . '</span></td><td>' . esc_html( ! empty($rule['enabled']) ? __('Enabled', 'onesmtp') : __('Disabled', 'onesmtp')) . '</td><td><form method="post" action="' . esc_url(admin_url('options-general.php?page=onesmtp&tab=onesmtp-routing#onesmtp-routing')) . '"><input type="hidden" name="onesmtp_routing_action" value="delete"><input type="hidden" name="rule_id" value="' . esc_attr( (string) ( (int) ($rule['id'] ?? 0))) . '">' . wp_nonce_field(self::ACTION_NAME, self::NONCE_NAME, true, false) . '<button type="submit" class="button button-secondary">' . esc_html__('Delete', 'onesmtp') . '</button></form></td></tr>';
        }
        echo '</tbody></table></div>';
    }

    /**
     * @param array<int,array<string,mixed>> $activeProviders
     */
    private function renderAddForm(array $activeProviders): void
    {
        echo '<form class="onesmtp-routing-form" method="post" action="' . esc_url(admin_url('options-general.php?page=onesmtp&tab=onesmtp-routing#onesmtp-routing')) . '"><input type="hidden" name="onesmtp_routing_action" value="save">';
        wp_nonce_field(self::ACTION_NAME, self::NONCE_NAME);
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="onesmtp-routing-provider">' . esc_html__('Provider', 'onesmtp') . '</label></th><td><select id="onesmtp-routing-provider" name="provider_id" required>';
        foreach ($activeProviders as $provider) {
            $id = (int) ($provider['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            echo '<option value="' . esc_attr( (string) $id) . '">' . esc_html( (string) ($provider['name'] ?? __('Provider', 'onesmtp'))) . '</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th scope="row"><label for="onesmtp-routing-priority">' . esc_html__('Priority', 'onesmtp') . '</label></th><td><input id="onesmtp-routing-priority" class="small-text" type="number" min="1" max="9999" name="priority" value="100" required><p class="description">' . esc_html__('Lower numbers are evaluated first. Ties keep their configured order.', 'onesmtp') . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="onesmtp-routing-field">' . esc_html__('Condition', 'onesmtp') . '</label></th><td><select id="onesmtp-routing-field" name="condition_field">';
        foreach (RoutingRuleNormalizer::FIELDS as $field) {
            echo '<option value="' . esc_attr($field) . '">' . esc_html($this->fieldLabel($field)) . '</option>';
        }
        echo '</select> <select name="condition_operator" aria-label="' . esc_attr__('Condition operator', 'onesmtp') . '">';
        foreach (RoutingRuleNormalizer::OPERATORS as $operator) {
            if ($operator === 'in' || $operator === 'exists') {
                continue;
            }
            echo '<option value="' . esc_attr($operator) . '">' . esc_html($this->operatorLabel($operator)) . '</option>';
        }
        echo '</select><br><textarea id="onesmtp-routing-value" class="large-text" name="condition_value" rows="3" maxlength="' . esc_attr( (string) RoutingRuleNormalizer::MAX_VALUE_LENGTH) . '" required></textarea><p class="description">' . esc_html__('Use a sender/recipient address, subject phrase, message phrase, or source label. Values are never included in Aculect Mail logs.', 'onesmtp') . '</p></td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Rule status', 'onesmtp') . '</th><td><label><input type="checkbox" name="enabled" value="1" checked> ' . esc_html__('Enable this rule', 'onesmtp') . '</label></td></tr>';
        echo '</tbody></table><p class="submit"><button type="submit" class="button button-primary">' . esc_html__('Add routing rule', 'onesmtp') . '</button></p></form>';
    }

    private function renderStatus(string $status, string $message): void
    {
        $class = '';
        $text = '';
        if ($status === 'saved') {
            $class = 'success';
            $text = __('Routing rule saved.', 'onesmtp');
        } elseif ($status === 'deleted') {
            $class = 'success';
            $text = __('Routing rule deleted.', 'onesmtp');
        } elseif ($status === 'upgrade_required') {
            $class = 'warning';
            $text = __('Conditional routing rules require an enabled Pro capability.', 'onesmtp');
        } elseif ($status === 'invalid') {
            $class = 'error';
            $text = $message !== '' ? $message : __('The routing rule could not be saved. Review the fields and try again.', 'onesmtp');
        } elseif ($status === 'failure') {
            $class = 'error';
            $text = __('Aculect Mail could not update the routing rules. Refresh the page and try again.', 'onesmtp');
        }

        if ($class !== '') {
            echo '<div class="notice notice-' . esc_attr($class) . ' inline"><p>' . esc_html($text) . '</p></div>';
        }
    }

    private function redirect(string $status, string $message = ''): void
    {
        $args = ['onesmtp_routing_status' => $status];
        if ($message !== '') {
            $args['onesmtp_routing_message'] = $message;
        }

        wp_safe_redirect(add_query_arg($args, admin_url('options-general.php?page=onesmtp&tab=onesmtp-routing#onesmtp-routing')));
        throw new RuntimeException('Aculect Mail routing admin redirected.');
    }

    private function fieldLabel(string $field): string
    {
        return match ($field) {
            'sender' => __('Sender', 'onesmtp'),
            'recipient' => __('Recipient', 'onesmtp'),
            'subject' => __('Subject', 'onesmtp'),
            'content' => __('Content', 'onesmtp'),
            'source' => __('Source attribution', 'onesmtp'),
            'source_type' => __('Source type', 'onesmtp'),
            'source_slug' => __('Source slug', 'onesmtp'),
            'source_name' => __('Source name', 'onesmtp'),
            'source_origin' => __('Source origin', 'onesmtp'),
            default => __('Condition', 'onesmtp'),
        };
    }

    private function operatorLabel(string $operator): string
    {
        return match ($operator) {
            'contains' => __('contains', 'onesmtp'),
            'starts_with' => __('starts with', 'onesmtp'),
            'ends_with' => __('ends with', 'onesmtp'),
            'in' => __('is one of', 'onesmtp'),
            'exists' => __('is present', 'onesmtp'),
            default => __('equals', 'onesmtp'),
        };
    }

    private function hasActiveProvider(int $providerId): bool
    {
        if ($providerId <= 0) {
            return false;
        }

        foreach ($this->providers->getActiveProviders() as $provider) {
            if ( (int) ($provider['id'] ?? 0) === $providerId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findRule(int $ruleId): ?array
    {
        foreach ($this->rules->get() as $rule) {
            if ( (int) ($rule['id'] ?? 0) === $ruleId) {
                return $rule;
            }
        }

        return null;
    }
}
