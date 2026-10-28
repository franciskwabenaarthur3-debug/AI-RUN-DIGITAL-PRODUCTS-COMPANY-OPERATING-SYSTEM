<?php

namespace App\Ai;

class ModelCatalog
{
    public function find(?string $key): ?ModelConfig
    {
        $entry = $key === null ? null : config("company_os.ai.models.{$key}");

        if (! is_array($entry)) {
            return null;
        }

        return new ModelConfig(
            key: $key,
            label: $entry['label'],
            provider: $entry['provider'],
            model: $entry['model'],
            version: $entry['version'],
            inputMinorPerMtok: $entry['input_minor_per_mtok'],
            outputMinorPerMtok: $entry['output_minor_per_mtok'],
            maxOutputTokens: $entry['max_output_tokens'],
            effort: $entry['effort'] ?? null,
            webSearch: (bool) ($entry['web_search'] ?? false),
        );
    }

    /**
     * The catalog entry whose provider model id matches, for pricing a
     * response served by a different model (e.g. a refusal fallback).
     */
    public function forProviderModel(string $provider, string $model): ?ModelConfig
    {
        foreach ($this->keys() as $key) {
            $config = $this->find($key);
            if ($config->provider === $provider && $config->model === $model) {
                return $config;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys(config('company_os.ai.models'));
    }

    /**
     * @return array<string, string> key => label
     */
    public function options(): array
    {
        return array_map(fn ($m) => $m['label'], config('company_os.ai.models'));
    }
}
