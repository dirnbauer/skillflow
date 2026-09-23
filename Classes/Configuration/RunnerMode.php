<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Configuration;

/**
 * The classic chain's runner choice (extension setting "runner").
 */
enum RunnerMode: string
{
    /** Prefer the LLM connection configured in nr_llm, fall back to the Anthropic API key. */
    case Api = 'api';
    /** Always use the Anthropic Messages API with the environment key. */
    case Anthropic = 'anthropic';
    /** Run the local Claude Code CLI. */
    case Cli = 'cli';
}
