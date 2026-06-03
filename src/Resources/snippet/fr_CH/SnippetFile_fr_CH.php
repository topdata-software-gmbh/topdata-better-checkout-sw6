<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Resources\snippet\fr_CH;

use Shopware\Core\System\Snippet\Files\SnippetFileInterface;

class SnippetFile_fr_CH implements SnippetFileInterface
{
    public function getName(): string { return 'storefront.fr-CH'; }
    public function getPath(): string { return __DIR__ . '/storefront.fr-CH.json'; }
    public function getIso(): string { return 'fr-CH'; }
    public function getAuthor(): string { return 'TopData Software GmbH'; }
    public function isBase(): bool { return false; }
}
