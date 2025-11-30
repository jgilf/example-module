<?php

/**
 * External hints module prototype.
 */

declare(strict_types=1);

namespace ExternalHints;

use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\ModuleTabInterface;
use Fisharebest\Webtrees\Module\ModuleTabTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * A module that surfaces external research hints on individual pages.
 */
class ExternalHintsModule extends AbstractModule implements ModuleCustomInterface, ModuleTabInterface
{
    use ModuleCustomTrait;
    use ModuleTabTrait;

    /** @var ExternalHintsProviderInterface[] */
    private array $providers;

    public function __construct()
    {
        $this->providers = [
            new MockProvider(),
        ];
    }

    /**
     * Bootstrap. Register the JSON endpoint that the tab UI will use.
     */
    public function boot(): void
    {
        $module = $this;

        if (class_exists(Route::class)) {
            Route::get('/module.php/external-hints/search', static function (Request $request) use ($module): JsonResponse {
                return $module->handleSearch($request);
            })->name('module.external-hints.search');
        }
    }

    public function title(): string
    {
        return I18N::translate('External hints');
    }

    public function description(): string
    {
        return I18N::translate('Search external genealogy sites and attach hints directly to individuals.');
    }

    public function customModuleAuthorName(): string
    {
        return 'External Hints Prototype';
    }

    public function customModuleVersion(): string
    {
        return '0.1.0';
    }

    public function customModuleLatestVersionUrl(): string
    {
        return 'https://example.com/external-hints/latest-version.txt';
    }

    public function customModuleSupportUrl(): string
    {
        return 'https://example.com/external-hints';
    }

    public function defaultTabOrder(): int
    {
        return 30;
    }

    public function tabTitle(Individual $individual): string
    {
        return I18N::translate('Hints');
    }

    public function hasTabContent(Individual $individual): bool
    {
        return true;
    }

    public function tabContent(Individual $individual): string
    {
        $endpoint = $this->buildEndpoint($individual);
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
<div class="wt-card" id="external-hints" data-endpoint="{$escape($endpoint)}">
    <div class="wt-card-body">
        <p class="wt-muted">{$escape(I18N::translate('Loading external hints…'))}</p>
    </div>
</div>
<script>
(function () {
    var root = document.getElementById('external-hints');
    if (!root) { return; }
    var endpoint = root.dataset.endpoint;
    var body = root.querySelector('.wt-card-body');

    function renderError(message) {
        body.innerHTML = '<p>' + message + '</p>';
    }

    function renderProviders(payload) {
        if (!payload.providers || !payload.providers.length) {
            body.innerHTML = '<p>No hints yet. Try refreshing.</p>';
            return;
        }

        body.innerHTML = '';
        payload.providers.forEach(function (provider) {
            var providerBlock = document.createElement('div');
            providerBlock.className = 'wt-mb-2';
            var heading = document.createElement('div');
            heading.className = 'wt-bold';
            heading.textContent = provider.label;
            providerBlock.appendChild(heading);

            if (!provider.results || !provider.results.length) {
                var empty = document.createElement('p');
                empty.className = 'wt-muted wt-m-0';
                empty.textContent = 'No hints from this provider.';
                providerBlock.appendChild(empty);
                body.appendChild(providerBlock);
                return;
            }

            var list = document.createElement('ul');
            list.className = 'wt-list-unstyled wt-m-0';

            provider.results.forEach(function (result) {
                var item = document.createElement('li');
                item.className = 'wt-mb-1';

                var title = document.createElement('div');
                title.className = 'wt-bold';
                title.textContent = result.title;
                item.appendChild(title);

                if (result.snippet) {
                    var snippet = document.createElement('div');
                    snippet.className = 'wt-muted';
                    snippet.textContent = result.snippet;
                    item.appendChild(snippet);
                }

                var actions = document.createElement('div');
                actions.className = 'wt-mt-1';

                if (result.url) {
                    var viewLink = document.createElement('a');
                    viewLink.href = result.url;
                    viewLink.target = '_blank';
                    viewLink.rel = 'noreferrer';
                    viewLink.textContent = 'View on site';
                    actions.appendChild(viewLink);
                }

                var attachBtn = document.createElement('button');
                attachBtn.type = 'button';
                attachBtn.className = 'btn btn-sm btn-primary wt-ml-1';
                attachBtn.textContent = 'Attach as source';
                attachBtn.onclick = function () {
                    alert('Wire this up to create a source/note in GEDCOM for ' + result.title);
                };
                actions.appendChild(attachBtn);

                var ignoreBtn = document.createElement('button');
                ignoreBtn.type = 'button';
                ignoreBtn.className = 'btn btn-sm btn-secondary wt-ml-1';
                ignoreBtn.textContent = 'Ignore';
                ignoreBtn.onclick = function () {
                    item.style.display = 'none';
                };
                actions.appendChild(ignoreBtn);

                item.appendChild(actions);
                list.appendChild(item);
            });

            providerBlock.appendChild(list);
            body.appendChild(providerBlock);
        });
    }

    fetch(endpoint, {headers: {'Accept': 'application/json'}})
        .then(function (response) {
            if (!response.ok) {
                throw new Error('Bad response');
            }
            return response.json();
        })
        .then(function (payload) {
            renderProviders(payload);
        })
        .catch(function () {
            renderError('Unable to load hints right now.');
        });
})();
</script>
HTML;

        return $html;
    }

    /**
     * Handle the /external-hints/search endpoint and return mock provider data.
     */
    public function handleSearch(Request $request): JsonResponse
    {
        $context = [
            'tree' => (string) $request->query('tree', ''),
            'xref' => (string) $request->query('xref', ''),
            'name' => (string) $request->query('name', ''),
        ];

        $providers = [];
        foreach ($this->providers as $provider) {
            $providers[] = [
                'provider' => $provider->id(),
                'label'    => $provider->label(),
                'results'  => $provider->search($context),
            ];
        }

        return new JsonResponse([
            'individual' => $context,
            'providers'  => $providers,
        ]);
    }

    /**
     * Build the endpoint URL the tab should call for JSON hints.
     */
    private function buildEndpoint(Individual $individual): string
    {
        $treeName = rawurlencode($individual->tree()->name());
        $xref = rawurlencode($individual->xref());

        return "/module.php/external-hints/search?tree={$treeName}&xref={$xref}";
    }
}

interface ExternalHintsProviderInterface
{
    /**
     * Machine-readable provider identifier.
     */
    public function id(): string;

    /**
     * Human-readable provider label.
     */
    public function label(): string;

    /**
     * Return search results for a given individual context.
     *
     * @param array<string,string> $context
     * @return array<int,array<string,mixed>>
     */
    public function search(array $context): array;
}

class MockProvider implements ExternalHintsProviderInterface
{
    public function id(): string
    {
        return 'mock-records';
    }

    public function label(): string
    {
        return 'Demo provider (replace me)';
    }

    public function search(array $context): array
    {
        $xref = $context['xref'] ?: 'INDI';
        $name = $context['name'] ?: 'Unknown person';

        return [
            [
                'id'         => "{$xref}-census-1900",
                'title'      => "Possible census entry for {$name} (1900)",
                'url'        => 'https://example.com/census/1900/' . rawurlencode($xref),
                'snippet'    => 'Household listing with matching surname and birth year +/- 2.',
                'confidence' => 0.72,
            ],
            [
                'id'         => "{$xref}-findagrave",
                'title'      => "Gravestone match for {$name}",
                'url'        => 'https://example.com/findagrave/' . rawurlencode($xref),
                'snippet'    => 'Inscription includes familiar family names.',
                'confidence' => 0.41,
            ],
        ];
    }
}
