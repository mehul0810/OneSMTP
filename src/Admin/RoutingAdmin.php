<?php

declare(strict_types=1);

namespace OneSMTP\Admin;

use InvalidArgumentException;
use OneSMTP\Audit\AdminAuditLogger;
use OneSMTP\Core\Capabilities;
use OneSMTP\Dispatch\RoutingRuleNormalizer;
use OneSMTP\Dispatch\RoutingRuleSimulator;
use OneSMTP\Dispatch\RoutingRulesRepository;
use OneSMTP\Product\FeatureGate;
use OneSMTP\Repository\ProviderRepository;
use RuntimeException;

final class RoutingAdmin
{
    private const ACTION_NAME = 'onesmtp_routing_action';
    private const NONCE_NAME = 'onesmtp_routing_nonce';
    private const SIMULATION_SOURCE_SAVED = 'saved';
    private const SIMULATION_SOURCE_CANDIDATE = 'candidate';

    /** @var array<string,mixed>|null */
    private ?array $simulationResult = null;

    /** @var array<string,mixed>|null */
    private ?array $simulationSample = null;

    /** @var array<string,mixed>|null */
    private ?array $simulationCandidate = null;

    private string $simulationSource = self::SIMULATION_SOURCE_SAVED;

    public function __construct(
        private ?RoutingRulesRepository $rules = null,
        private ?ProviderRepository $providers = null,
        private ?FeatureGate $featureGate = null,
        private ?AdminRequest $request = null,
        private ?AdminAuditLogger $auditLogger = null,
        private ?RoutingRuleSimulator $simulator = null
    ) {
        $this->rules = $rules ?? new RoutingRulesRepository();
        $this->providers = $providers ?? new ProviderRepository();
        $this->featureGate = $featureGate ?? new FeatureGate();
        $this->request = $request ?? new AdminRequest();
        $this->auditLogger = $auditLogger ?? new AdminAuditLogger();
        $this->simulator = $simulator ?? new RoutingRuleSimulator(featureGate: $this->featureGate);
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
        if ( ! in_array($action, ['save', 'update', 'delete', 'simulate'], true)) {
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

        if ($action === 'simulate') {
            $this->handleSimulation();

            return;
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
            $rule = [
                'provider_id' => $providerId,
                'priority' => isset($_POST['priority']) ? absint($_POST['priority']) : 100,
                'enabled' => isset($_POST['enabled']),
                'conditions' => [$condition],
            ];
            $ruleId = $action === 'update' && isset($_POST['rule_id']) ? absint($_POST['rule_id']) : 0;
            $saved = $action === 'update'
                ? $this->rules->update($ruleId, $rule)
                : $this->rules->add($rule);

            if ($saved) {
                $storedRule = $action === 'update' ? $this->findRule($ruleId) : null;
                if ($storedRule === null) {
                    $storedRules = $this->rules->get();
                    $storedRule = $storedRules[ array_key_last($storedRules) ] ?? [];
                }
                $this->auditLogger->logRoutingRuleChange(
                    $action === 'update' ? 'updated' : 'created',
                    (int) ($storedRule['id'] ?? 0),
                    $providerId,
                    (int) ($_POST['priority'] ?? 100),
                    1,
                    isset($_POST['enabled'])
                );
            }

            $this->redirect($saved ? ($action === 'update' ? 'updated' : 'saved') : 'failure');
        } catch (InvalidArgumentException $exception) {
            $this->redirect('invalid', $exception->getMessage());
        }
    }

    private function handleSimulation(): void
    {
        $source = sanitize_key($this->postedText('simulation_source'));
        $this->simulationSource = in_array($source, [self::SIMULATION_SOURCE_SAVED, self::SIMULATION_SOURCE_CANDIDATE], true)
            ? $source
            : self::SIMULATION_SOURCE_SAVED;
        $this->simulationSample = $this->simulationSampleFromPost();
        $this->simulationCandidate = $this->routingRuleFromPost('simulation_');

        $rules = $this->simulationSource === self::SIMULATION_SOURCE_CANDIDATE
            ? ($this->simulationCandidate === null ? [] : [$this->simulationCandidate])
            : $this->rules->get();
        $providers = $this->providers->getAll();
        if ($providers === []) {
            $providers = $this->providers->getActiveProviders();
        }

        $this->simulationResult = $this->simulator->simulate(
            $rules,
            $this->simulationSample,
            $providers,
            $this->simulationSource === self::SIMULATION_SOURCE_CANDIDATE
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function simulationSampleFromPost(): array
    {
        $sample = [];
        foreach (['sender', 'recipient', 'subject', 'content', 'source_type', 'source_slug', 'source_name', 'source_origin'] as $field) {
            $sample[ $field ] = $this->postedText('simulation_' . $field);
        }

        return $sample;
    }

    // phpcs:disable WordPress.Security.NonceVerification.Missing -- handleRequest verifies the action nonce before invoking these request readers.
    /**
     * @return array<string,mixed>|null
     */
    private function routingRuleFromPost(string $prefix = ''): ?array
    {
        $providerKey = $prefix . 'provider_id';
        $priorityKey = $prefix . 'priority';
        $fieldKey = $prefix . 'condition_field';
        $operatorKey = $prefix . 'condition_operator';
        $valueKey = $prefix . 'condition_value';
        $enabledKey = $prefix . 'enabled';
        $providerId = isset($_POST[ $providerKey ]) ? absint($_POST[ $providerKey ]) : 0;
        $field = $this->postedText($fieldKey);
        $operator = $this->postedText($operatorKey);
        $value = $this->postedText($valueKey);

        if ($providerId <= 0 && $field === '' && $operator === '' && $value === '' && ! isset($_POST[ $enabledKey ])) {
            return null;
        }

        return [
            'provider_id' => $providerId,
            'priority' => isset($_POST[ $priorityKey ]) ? absint($_POST[ $priorityKey ]) : 100,
            'enabled' => isset($_POST[ $enabledKey ]),
            'conditions' => [
                [
                    'field' => $field,
                    'operator' => $operator,
                    'value' => $value,
                ],
            ],
        ];
    }

    private function postedText(string $key): string
    {
        if ( ! isset($_POST[ $key ]) || ! is_scalar($_POST[ $key ])) {
            return '';
        }

        return wp_unslash( (string) $_POST[ $key ]);
    }
    // phpcs:enable WordPress.Security.NonceVerification.Missing

    /**
     * @param array<int,array<string,mixed>> $activeProviders
     */
    public function render(array $activeProviders = []): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect status is sanitized and only changes the local notice.
        $status = isset($_GET['onesmtp_routing_status'])
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect status is sanitized and only changes the local notice.
            ? sanitize_key(wp_unslash( (string) $_GET['onesmtp_routing_status']))
            : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect status is sanitized and only changes the local notice.
        $message = isset($_GET['onesmtp_routing_message'])
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect status is sanitized and only changes the local notice.
            ? sanitize_text_field(wp_unslash( (string) $_GET['onesmtp_routing_message']))
            : '';
        $this->renderStatus($status, $message);

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only edit selector is sanitized and only selects the local form state.
        $editRuleId = isset($_GET['onesmtp_routing_edit']) ? absint($_GET['onesmtp_routing_edit']) : 0;
        $editRule = $editRuleId > 0 ? $this->findRule($editRuleId) : null;

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
            $this->renderRuleForm($activeProviders, $editRule);
        }

        $this->renderSimulation($activeProviders);

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
            $editUrl = add_query_arg(
                [
					'page' => 'onesmtp',
					'tab' => 'onesmtp-routing',
					'onesmtp_routing_edit' => (int) ($rule['id'] ?? 0),
				],
                admin_url('options-general.php')
            ) . '#onesmtp-routing';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field returns the complete nonce input markup.
            echo '<tr><td>' . esc_html( (string) ( (int) ($rule['priority'] ?? 100))) . '</td><td>' . esc_html($providerNames[ $providerId ] ?? __('Unavailable provider', 'onesmtp')) . '</td><td>' . esc_html($this->fieldLabel($field) . ' — ' . $this->operatorLabel($operator)) . '<br><span class="description">' . esc_html__('Configured value is hidden on this screen.', 'onesmtp') . '</span></td><td>' . esc_html( ! empty($rule['enabled']) ? __('Enabled', 'onesmtp') : __('Disabled', 'onesmtp')) . '</td><td><a class="button button-secondary" href="' . esc_url($editUrl) . '">' . esc_html__('Edit', 'onesmtp') . '</a> <form class="onesmtp-routing-inline-form" method="post" action="' . esc_url(admin_url('options-general.php?page=onesmtp&tab=onesmtp-routing#onesmtp-routing')) . '"><input type="hidden" name="onesmtp_routing_action" value="delete"><input type="hidden" name="rule_id" value="' . esc_attr( (string) ( (int) ($rule['id'] ?? 0))) . '">' . wp_nonce_field(self::ACTION_NAME, self::NONCE_NAME, true, false) . '<button type="submit" class="button button-secondary">' . esc_html__('Delete', 'onesmtp') . '</button></form></td></tr>';
        }
        echo '</tbody></table></div>';
    }

    /**
     * @param array<int,array<string,mixed>> $activeProviders
     */
    private function renderSimulation(array $activeProviders): void
    {
        if ( ! $this->featureGate->isEnabled(FeatureGate::SMART_ROUTING)) {
            return;
        }

        echo '<section class="onesmtp-routing-simulation" aria-labelledby="onesmtp-routing-simulation-heading">';
        echo '<h4 id="onesmtp-routing-simulation-heading">' . esc_html__('Simulate routing decision', 'onesmtp') . '</h4>';
        echo '<p class="description">' . esc_html__('Use synthetic sample values to preview a routing decision. Samples stay in memory for this request only: no provider call, queue, retry, message, attempt, event, audit record, or rule update is created.', 'onesmtp') . '</p>';
        echo '<p class="description">' . esc_html(sprintf(
            /* translators: %d: maximum characters inspected from a sample content value. */
            __('Only the first %d content characters are inspected.', 'onesmtp'),
            RoutingRuleNormalizer::MAX_MATCH_LENGTH
        )) . '</p>';

        if ($this->simulationResult === null) {
            echo '<div class="notice notice-info inline" role="status"><p>' . esc_html__('Enter a sample and simulate to see the matched rule or safe no-match result.', 'onesmtp') . '</p></div>';
        } else {
            $this->renderSimulationResult($this->simulationResult);
        }

        $sample = $this->simulationSample ?? [];
        $candidate = $this->simulationCandidate ?? [];
        $condition = is_array($candidate['conditions'][0] ?? null) ? $candidate['conditions'][0] : [];
        $providerValue = (int) ($candidate['provider_id'] ?? ($activeProviders[0]['id'] ?? 0));
        $priorityValue = (int) ($candidate['priority'] ?? 100);
        $fieldValue = (string) ($condition['field'] ?? 'sender');
        $operatorValue = (string) ($condition['operator'] ?? 'equals');
        $conditionValue = (string) ($condition['value'] ?? '');
        $candidateEnabled = ! array_key_exists('enabled', $candidate) || ! empty($candidate['enabled']);
        $sourceValue = $this->simulationSource;

        echo '<form class="onesmtp-routing-simulation-form" method="post" action="' . esc_url(admin_url('options-general.php?page=onesmtp&tab=onesmtp-routing#onesmtp-routing')) . '"><input type="hidden" name="onesmtp_routing_action" value="simulate">';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field returns the complete nonce input markup.
        echo wp_nonce_field(self::ACTION_NAME, self::NONCE_NAME, true, false);
        echo '<fieldset><legend>' . esc_html__('Rules to simulate', 'onesmtp') . '</legend><label for="onesmtp-routing-simulation-source">' . esc_html__('Rule source', 'onesmtp') . '</label> <select id="onesmtp-routing-simulation-source" name="simulation_source">';
        echo '<option value="saved"' . ($sourceValue === self::SIMULATION_SOURCE_SAVED ? ' selected="selected"' : '') . '>' . esc_html__('Current saved rules', 'onesmtp') . '</option>';
        echo '<option value="candidate"' . ($sourceValue === self::SIMULATION_SOURCE_CANDIDATE ? ' selected="selected"' : '') . '>' . esc_html__('Unsaved candidate rule set', 'onesmtp') . '</option>';
        echo '</select><p class="description">' . esc_html__('The candidate set is one bounded rule with the same fields and operators used by saved routing rules.', 'onesmtp') . '</p>';
        echo '<div class="onesmtp-routing-simulation-candidate"><label for="onesmtp-routing-simulation-provider">' . esc_html__('Candidate provider', 'onesmtp') . '</label> <select id="onesmtp-routing-simulation-provider" name="simulation_provider_id">';
        if ($activeProviders === []) {
            echo '<option value="0">' . esc_html__('No active providers', 'onesmtp') . '</option>';
        }
        foreach ($activeProviders as $provider) {
            $id = (int) ($provider['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            echo '<option value="' . esc_attr( (string) $id) . '"' . ($id === $providerValue ? ' selected="selected"' : '') . '>' . esc_html( (string) ($provider['name'] ?? __('Provider', 'onesmtp'))) . '</option>';
        }
        echo '</select> <label for="onesmtp-routing-simulation-priority">' . esc_html__('Priority', 'onesmtp') . '</label> <input id="onesmtp-routing-simulation-priority" class="small-text" type="number" min="1" max="9999" name="simulation_priority" value="' . esc_attr( (string) $priorityValue) . '">';
        echo '<label for="onesmtp-routing-simulation-field">' . esc_html__('Condition', 'onesmtp') . '</label> <select id="onesmtp-routing-simulation-field" name="simulation_condition_field">';
        foreach (RoutingRuleNormalizer::FIELDS as $field) {
            echo '<option value="' . esc_attr($field) . '"' . ($field === $fieldValue ? ' selected="selected"' : '') . '>' . esc_html($this->fieldLabel($field)) . '</option>';
        }
        echo '</select> <select name="simulation_condition_operator" aria-label="' . esc_attr__('Candidate condition operator', 'onesmtp') . '">';
        foreach (RoutingRuleNormalizer::OPERATORS as $operator) {
            echo '<option value="' . esc_attr($operator) . '"' . ($operator === $operatorValue ? ' selected="selected"' : '') . '>' . esc_html($this->operatorLabel($operator)) . '</option>';
        }
        echo '</select><label for="onesmtp-routing-simulation-value">' . esc_html__('Condition value', 'onesmtp') . '</label><textarea id="onesmtp-routing-simulation-value" class="large-text" name="simulation_condition_value" rows="2" maxlength="' . esc_attr( (string) RoutingRuleNormalizer::MAX_VALUE_LENGTH) . '">' . esc_textarea($conditionValue) . '</textarea>';
        $checked = $candidateEnabled ? ' checked="checked"' : '';
        echo '<label><input type="checkbox" name="simulation_enabled" value="1"' . esc_attr($checked) . '> ' . esc_html__('Include candidate rule', 'onesmtp') . '</label></div></fieldset>';

        echo '<fieldset><legend>' . esc_html__('Sample message fields', 'onesmtp') . '</legend>';
        $this->renderSimulationInput('sender', __('Sender', 'onesmtp'), $sample, 'text');
        $this->renderSimulationInput('recipient', __('Recipient(s)', 'onesmtp'), $sample, 'text', __('Separate multiple recipients with commas.', 'onesmtp'));
        $this->renderSimulationInput('subject', __('Subject', 'onesmtp'), $sample, 'text');
        $this->renderSimulationInput('content', __('Content', 'onesmtp'), $sample, 'textarea', __('Content is evaluated in memory and bounded to the first 4096 characters.', 'onesmtp'));
        $this->renderSimulationInput('source_type', __('Source type', 'onesmtp'), $sample, 'text');
        $this->renderSimulationInput('source_slug', __('Source slug', 'onesmtp'), $sample, 'text');
        $this->renderSimulationInput('source_name', __('Source name', 'onesmtp'), $sample, 'text');
        $this->renderSimulationInput('source_origin', __('Source origin', 'onesmtp'), $sample, 'text');
        echo '</fieldset><p class="submit"><button type="submit" class="button button-secondary">' . esc_html__('Simulate routing', 'onesmtp') . '</button></p></form></section>';
    }

    /**
     * @param array<string,mixed> $sample
     */
    private function renderSimulationInput(string $field, string $label, array $sample, string $type, string $description = ''): void
    {
        $value = isset($sample[ $field ]) && is_scalar($sample[ $field ])
            ? substr( (string) $sample[ $field ], 0, RoutingRuleNormalizer::MAX_MATCH_LENGTH)
            : '';
        $inputId = 'onesmtp-routing-simulation-' . $field;
        echo '<p><label for="' . esc_attr($inputId) . '">' . esc_html($label) . '</label>';
        if ($type === 'textarea') {
            echo '<textarea id="' . esc_attr($inputId) . '" class="large-text" name="simulation_' . esc_attr($field) . '" rows="5" maxlength="' . esc_attr( (string) RoutingRuleNormalizer::MAX_MATCH_LENGTH) . '">' . esc_textarea($value) . '</textarea>';
        } else {
            echo '<input id="' . esc_attr($inputId) . '" class="regular-text" type="text" name="simulation_' . esc_attr($field) . '" value="' . esc_attr($value) . '" maxlength="' . esc_attr( (string) RoutingRuleNormalizer::MAX_MATCH_LENGTH) . '">';
        }
        if ($description !== '') {
            echo '<span class="description">' . esc_html($description) . '</span>';
        }
        echo '</p>';
    }

    /**
     * @param array<string,mixed> $result
     */
    private function renderSimulationResult(array $result): void
    {
        $status = (string) ($result['status'] ?? 'error');
        $class = 'info';
        $message = __('No matching rule. The normal healthy-provider route would handle this sample.', 'onesmtp');
        if ($status === 'matched') {
            $class = 'success';
            $providerName = (string) ($result['provider_name'] ?? __('Unavailable provider', 'onesmtp'));
            $ruleId = (int) ($result['rule_id'] ?? 0);
            $message = sprintf(
                /* translators: 1: rule ID, 2: provider name. */
                __('Matched rule #%1$d; selected provider: %2$s.', 'onesmtp'),
                $ruleId,
                $providerName
            );
        } elseif ($status === 'sample_empty') {
            $class = 'warning';
            $message = __('Enter at least one sample field before simulating.', 'onesmtp');
        } elseif ($status === 'candidate_empty') {
            $class = 'warning';
            $message = __('Add a candidate rule before simulating the unsaved rule set.', 'onesmtp');
        } elseif ($status === 'candidate_invalid') {
            $class = 'error';
            $message = __('The candidate rule is invalid. Review its provider, condition, and bounded value.', 'onesmtp');
        } elseif ($status === 'no_rules') {
            $message = __('No saved routing rules are configured. The normal healthy-provider route would handle this sample.', 'onesmtp');
        } elseif ($status === 'no_eligible_provider') {
            $class = 'warning';
            $message = __('No eligible provider was available. No message was sent or queued.', 'onesmtp');
        } elseif ($status === 'pro_required') {
            $class = 'warning';
            $message = __('Routing simulation requires an enabled Pro capability.', 'onesmtp');
        }

        echo '<div class="notice notice-' . esc_attr($class) . ' inline" role="status" aria-live="polite"><p>' . esc_html($message) . '</p>';
        $truncatedFields = isset($result['truncated_fields']) && is_array($result['truncated_fields']) ? $result['truncated_fields'] : [];
        if ($truncatedFields !== []) {
            echo '<p>' . esc_html__('Long sample values were bounded before matching: ', 'onesmtp') . esc_html(implode(', ', array_map(fn (mixed $field): string => $this->fieldLabel( (string) $field), $truncatedFields))) . '</p>';
        }
        echo '</div>';

        $effects = isset($result['provider_effects']) && is_array($result['provider_effects']) ? $result['provider_effects'] : [];
        if ($effects !== []) {
            echo '<details class="onesmtp-routing-simulation-effects"><summary>' . esc_html__('Provider eligibility effects', 'onesmtp') . '</summary><ul>';
            foreach (array_slice($effects, 0, 50) as $effect) {
                if ( ! is_array($effect)) {
                    continue;
                }
                $name = (string) ($effect['provider_name'] ?? __('Provider', 'onesmtp'));
                $state = (string) ($effect['state'] ?? 'eligible');
                $stateLabel = match ($state) {
                    'inactive' => __('inactive', 'onesmtp'),
                    'circuit_open' => __('skipped by open circuit breaker', 'onesmtp'),
                    default => __('eligible', 'onesmtp'),
                };
                echo '<li>' . esc_html($name . ': ' . $stateLabel) . '</li>';
            }
            echo '</ul></details>';
        }
    }

    /**
     * @param array<int,array<string,mixed>> $activeProviders
     */
    private function renderRuleForm(array $activeProviders, ?array $rule = null): void
    {
        $condition = is_array($rule['conditions'][0] ?? null) ? $rule['conditions'][0] : [];
        $action = $rule === null ? 'save' : 'update';
        $heading = $rule === null ? __('Add a routing rule', 'onesmtp') : __('Edit routing rule', 'onesmtp');
        echo '<h4>' . esc_html($heading) . '</h4><form class="onesmtp-routing-form" method="post" action="' . esc_url(admin_url('options-general.php?page=onesmtp&tab=onesmtp-routing#onesmtp-routing')) . '"><input type="hidden" name="onesmtp_routing_action" value="' . esc_attr($action) . '">';
        if ($rule !== null) {
            echo '<input type="hidden" name="rule_id" value="' . esc_attr( (string) (int) ($rule['id'] ?? 0)) . '">';
        }
        $providerValue = (int) ($rule['provider_id'] ?? ($activeProviders[0]['id'] ?? 0));
        $priorityValue = (int) ($rule['priority'] ?? 100);
        $fieldValue = (string) ($condition['field'] ?? 'sender');
        $operatorValue = (string) ($condition['operator'] ?? 'equals');
        $conditionValue = (string) ($condition['value'] ?? '');
        $enabledValue = $rule === null || ! empty($rule['enabled']);
        wp_nonce_field(self::ACTION_NAME, self::NONCE_NAME);
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="onesmtp-routing-provider">' . esc_html__('Provider', 'onesmtp') . '</label></th><td><select id="onesmtp-routing-provider" name="provider_id" required>';
        foreach ($activeProviders as $provider) {
            $id = (int) ($provider['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $selected = $id === $providerValue ? ' selected="selected"' : '';
            echo '<option value="' . esc_attr( (string) $id) . '"' . esc_attr($selected) . '>' . esc_html( (string) ($provider['name'] ?? __('Provider', 'onesmtp'))) . '</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th scope="row"><label for="onesmtp-routing-priority">' . esc_html__('Priority', 'onesmtp') . '</label></th><td><input id="onesmtp-routing-priority" class="small-text" type="number" min="1" max="9999" name="priority" value="' . esc_attr( (string) $priorityValue) . '" required><p class="description">' . esc_html__('Lower numbers are evaluated first. Ties keep their configured order.', 'onesmtp') . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="onesmtp-routing-field">' . esc_html__('Condition', 'onesmtp') . '</label></th><td><select id="onesmtp-routing-field" name="condition_field">';
        foreach (RoutingRuleNormalizer::FIELDS as $field) {
            $selected = $field === $fieldValue ? ' selected="selected"' : '';
            echo '<option value="' . esc_attr($field) . '"' . esc_attr($selected) . '>' . esc_html($this->fieldLabel($field)) . '</option>';
        }
        echo '</select> <select name="condition_operator" aria-label="' . esc_attr__('Condition operator', 'onesmtp') . '">';
        foreach (RoutingRuleNormalizer::OPERATORS as $operator) {
            if ($operator === 'in' || $operator === 'exists') {
                continue;
            }
            $selected = $operator === $operatorValue ? ' selected="selected"' : '';
            echo '<option value="' . esc_attr($operator) . '"' . esc_attr($selected) . '>' . esc_html($this->operatorLabel($operator)) . '</option>';
        }
        echo '</select><br><textarea id="onesmtp-routing-value" class="large-text" name="condition_value" rows="3" maxlength="' . esc_attr( (string) RoutingRuleNormalizer::MAX_VALUE_LENGTH) . '" required>' . esc_textarea($conditionValue) . '</textarea><p class="description">' . esc_html__('Use a sender/recipient address, subject phrase, message phrase, or source label. Values are never included in Aculect Mail logs.', 'onesmtp') . '</p></td></tr>';
        $checked = $enabledValue ? ' checked="checked"' : '';
        echo '<tr><th scope="row">' . esc_html__('Rule status', 'onesmtp') . '</th><td><label><input type="checkbox" name="enabled" value="1"' . esc_attr($checked) . '> ' . esc_html__('Enable this rule', 'onesmtp') . '</label></td></tr>';
        $submitLabel = $rule === null ? __('Add routing rule', 'onesmtp') : __('Update routing rule', 'onesmtp');
        echo '</tbody></table><p class="submit"><button type="submit" class="button button-primary">' . esc_html($submitLabel) . '</button></p></form>';
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
        } elseif ($status === 'updated') {
            $class = 'success';
            $text = __('Routing rule updated.', 'onesmtp');
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
