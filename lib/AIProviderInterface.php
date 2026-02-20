<?php

namespace Sahdev\Lib;

/**
 * Interface AIProviderInterface
 * Defines the contract for AI providers to ensure consistency when adding more.
 */
interface AIProviderInterface
{
    /**
     * Analyze a ticket and generate a structured response.
     *
     * @param array $context The extracted ticket data (subject, messages, etc.)
     * @param array $settings The module settings (temperature, max tokens, system prompt)
     * @param string $tone The tone requested by the user
     * @param string $customInstruction User's optional custom instructions
     * @return array Returns an array with structure: ['ROOT_CAUSE', 'RESPONSIBILITY', 'RISK_LEVEL', 'INTERNAL_ACTION_PLAN', 'CLIENT_REPLY']
     * @throws \Exception If the API call fails or times out
     */
    public function generateResponse(array $context, array $settings, string $tone, string $customInstruction): array;

    /**
     * Get recent token usage.
     * 
     * @return int The number of tokens consumed in the last request.
     */
    public function getLastTokenUsage(): int;

    /**
     * Refetch models dynamically
     *
     * @param string $apiKey Unencrypted API key to fetch available models
     * @return array List of model identifiers
     */
    public function getAvailableModels(string $apiKey): array;
}
