<?php

namespace App\Services;

class WorkflowRuleService
{
    /**
     * Evaluate rule array against the context parameters.
     */
    public function evaluateRule(array $rule, array $context): bool
    {
        foreach ($rule as $key => $expectedValue) {
            // Checks if context has the rule variable and value matches expected criteria
            if (! array_key_exists($key, $context)) {
                return false;
            }

            if ($context[$key] !== $expectedValue) {
                return false;
            }
        }

        return true;
    }
}
