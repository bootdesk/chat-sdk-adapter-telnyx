<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Telnyx;

use BootDesk\ChatSDK\Core\Markdown\BaseFormatConverter;
use League\CommonMark\Node\Block\Document;

class TelnyxFormatConverter extends BaseFormatConverter
{
    public function toAst(string $text): Document
    {
        return $this->parseMarkdown($text);
    }

    public function fromAst(Document $ast): string
    {
        return $this->renderMarkdown($ast);
    }
}
