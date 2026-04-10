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
     * Get detailed token usage (input vs output).
     * 
     * @return array An array with 'input' and 'output' token counts.
     */
    public function getLastTokenDetails(): array;

    /**
     * Get the provider type string (google or lmstudio).
     * 
     * @return string
     */
    public function getProviderType(): string;

    /**
     * Get the provider name (for audit logging).
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Get the API URL endpoint.
     * 
     * @return string
     */
    public function getApiUrl(): string;

    /**
     * Refetch models dynamically
     *
     * @param string $apiKey Unencrypted API key to fetch available models
     * @return array List of model identifiers
     */
    public function getAvailableModels(string $apiKey): array;
}
