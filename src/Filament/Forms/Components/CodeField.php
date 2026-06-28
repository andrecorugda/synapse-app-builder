<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Forms\Components;

use Closure;
use Filament\Forms\Components\Field;

/**
 * A Monaco (VS Code) code editor field — syntax highlighting, bracket matching,
 * in-browser JSON/CSS/JS validation, and (for php) server-side `php -l` linting.
 * The Monaco library + Alpine component are loaded via a panel render hook.
 */
class CodeField extends Field
{
    protected string $view = 'ai-page-builder::filament.code-field';

    protected string|Closure $language = 'plaintext';

    protected int|Closure $height = 260;

    public function language(string|Closure $language): static
    {
        $this->language = $language;

        return $this;
    }

    public function getLanguage(): string
    {
        return (string) $this->evaluate($this->language);
    }

    public function height(int|Closure $height): static
    {
        $this->height = $height;

        return $this;
    }

    public function getHeight(): int
    {
        return (int) $this->evaluate($this->height);
    }
}
