<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Resources\snippet\pt_PT;

use Shopware\Core\System\Snippet\Files\SnippetFileInterface;

class SnippetFile_pt_PT implements SnippetFileInterface
{
    public function getName(): string { return 'storefront.pt-PT'; }
    public function getPath(): string { return __DIR__ . '/storefront.pt-PT.json'; }
    public function getIso(): string { return 'pt-PT'; }
    public function getAuthor(): string { return 'TopData Software GmbH'; }
    public function isBase(): bool { return false; }
}
