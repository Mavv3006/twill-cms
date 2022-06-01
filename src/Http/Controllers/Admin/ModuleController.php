<?php

namespace A17\Twill\Http\Controllers\Admin;

use A17\Twill\Enums\TwillRouteActions;
use A17\Twill\Exceptions\NoCapsuleFoundException;
use A17\Twill\Facades\TwillBlocks;
use A17\Twill\Facades\TwillCapsules;
use A17\Twill\Facades\TwillRoutes;
use A17\Twill\Helpers\FlashLevel;
use A17\Twill\Models\Behaviors\HasSlug;
use A17\Twill\Models\Group;
use A17\Twill\Models\Model;
use A17\Twill\Services\Blocks\Block;
use A17\Twill\Services\Listings\Columns\Boolean;
use A17\Twill\Services\Listings\Columns\Browser;
use A17\Twill\Services\Listings\Columns\Image;
use A17\Twill\Services\Listings\Columns\Languages;
use A17\Twill\Services\Listings\Columns\NestedData;
use A17\Twill\Services\Listings\Columns\Presenter;
use A17\Twill\Services\Listings\Columns\PublishStatus;
use A17\Twill\Services\Listings\Columns\Relation;
use A17\Twill\Services\Listings\Columns\ScheduledStatus;
use A17\Twill\Services\Listings\Columns\Text;
use A17\Twill\Services\Listings\TableColumn;
use A17\Twill\Services\Listings\TableColumns;
use A17\Twill\Services\Listings\TableDataContext;
use A17\Twill\Services\Forms\Form;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model as BaseModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

abstract class ModuleController extends Controller
{
    /**
     * @var Application
     */
    protected $app;

    /**
     * @var Request
     */
    protected $request;

    /**
     * @var string
     */
    protected $namespace;

    /**
     * @var string
     * @deprecated set the model class to protected $module
     */
    protected $moduleName;

    /**
     * The Model class as in Model::class
     *
     * @todo: THIS IS A HARD REQUIREMENT TO BE SET
     */
    protected ?string $modelClass = null;

    /**
     * @var string
     */
    protected $modelName;

    /**
     * @var string
     */
    protected $modelTitle;

    /**
     * @var \A17\Twill\Repositories\ModuleRepository
     */
    protected $repository;

    /**
     * @var \A17\Twill\Models\User
     */
    protected $user;

    protected array $primaryNavigation = [];

    /**
     * Options of the index view.
     *
     * @var array
     */
    protected $defaultIndexOptions = [
        'create' => true,
        'edit' => true,
        'publish' => true,
        'bulkPublish' => true,
        'feature' => false,
        'bulkFeature' => false,
        'restore' => true,
        'bulkRestore' => true,
        'forceDelete' => true,
        'bulkForceDelete' => true,
        'delete' => true,
        'duplicate' => false,
        'bulkDelete' => true,
        'reorder' => false,
        'permalink' => true,
        'bulkEdit' => true,
        'editInModal' => false,
        'skipCreateModal' => false,
        'includeScheduledInList' => true,
        'showImage' => false,
    ];

    /**
     * Options of the index view and the corresponding auth gates.
     *
     * @var array
     */
    protected $authorizableOptions = [
        'list' => 'access-module-list',
        'create' => 'edit-module',
        'edit' => 'edit-item',
        'permalink' => 'edit-item',
        'publish' => 'edit-item',
        'feature' => 'edit-item',
        'reorder' => 'edit-module',
        'delete' => 'edit-item',
        'duplicate' => 'edit-item',
        'restore' => 'edit-item',
        'forceDelete' => 'edit-item',
        'bulkForceDelete' => 'edit-module',
        'bulkPublish' => 'edit-module',
        'bulkRestore' => 'edit-module',
        'bulkFeature' => 'edit-module',
        'bulkDelete' => 'edit-module',
        'bulkEdit' => 'edit-module',
        'editInModal' => 'edit-module',
        'skipCreateModal' => 'edit-module',
        'includeScheduledInList' => 'edit-module',
        'showImage' => 'edit-module',
    ];

    /**
     * Relations to eager load for the index view.
     *
     * @var array
     */
    protected $indexWith = [];

    /**
     * Relations to eager load for the form view.
     *
     * @var array
     */
    protected $formWith = [];

    /**
     * Relation count to eager load for the form view.
     *
     * @var array
     */
    protected $formWithCount = [];

    /**
     * Additional filters for the index view.
     *
     * To automatically have your filter added to the index view use the following convention:
     * suffix the key containing the list of items to show in the filter by 'List' and
     * name it the same as the filter you defined in this array.
     *
     * Example: 'fCategory' => 'category_id' here and 'fCategoryList' in indexData()
     * By default, this will run a where query on the category_id column with the value
     * of fCategory if found in current request parameters. You can intercept this behavior
     * from your repository in the filter() function.
     *
     * @var array
     */
    protected $filters = [];

    /**
     * Additional links to display in the listing filter.
     *
     * @var array
     */
    protected $filterLinks = [];

    /**
     * Filters that are selected by default in the index view.
     *
     * Example: 'filter_key' => 'default_filter_value'
     *
     * @var array
     */
    protected $filtersDefaultOptions = [];

    /**
     * Default orders for the index view.
     *
     * @var array
     */
    protected $defaultOrders = [
        'created_at' => 'desc',
    ];

    /**
     * @var int
     */
    protected $perPage = 20;

    /**
     * Name of the index column to use as name column.
     *
     * @var string
     */
    protected $titleColumnKey = 'title';

    /**
     * Name of the index column to use as identifier column.
     *
     * @var string
     */
    protected $identifierColumnKey = 'id';

    /**
     * Attribute to use as title in forms.
     *
     * @var string
     */
    protected $titleFormKey;

    /**
     * Feature field name if the controller is using the feature route (defaults to "featured").
     *
     * @var string
     */
    protected $featureField = 'featured';

    /**
     * Indicates if this module is edited through a parent module.
     *
     * @var bool
     */
    protected $submodule = false;

    /**
     * @var int|null
     */
    protected $submoduleParentId = null;

    /**
     * Can be used in child classes to disable the content editor (full screen block editor).
     *
     * @var bool
     */
    protected $disableEditor = false;

    /**
     * @var array
     */
    protected $indexOptions;

    /**
     * @deprecated please use the getIndexTableColumns method. Will be removed in Twill 4.0
     */
    protected ?array $indexColumns = null;

    /**
     * @deprecated please use the getBrowserTableColumns method. Will be removed in Twill 4.0
     */
    protected ?array $browserColumns = null;

    /**
     * @var string
     */
    protected $permalinkBase;

    /**
     * @var array
     */
    protected $defaultFilters;

    /**
     * @var string
     */
    protected $viewPrefix;

    /**
     * @var string
     */
    protected $previewView;

    /**
     * List of permissions keyed by a request field. Can be used to prevent unauthorized field updates.
     *
     * @var array
     */
    protected $fieldsPermissions = [];

    /**
     * Array of customizable label translation keys.
     *
     * @var array
     */
    protected $labels = [];

    /**
     * Default label translation keys that can be overridden in the labels array.
     *
     * @var array
     */
    protected $defaultLabels = [
        'published' => 'twill::lang.main.published',
        'draft' => 'twill::lang.main.draft',
        'listing' => [
            'filter' => [
                'published' => 'twill::lang.listing.filter.published',
                'draft' => 'twill::lang.listing.filter.draft',
            ],
        ],
    ];

    public function __construct(Application $app, Request $request)
    {
        parent::__construct();
        $this->app = $app;
        $this->request = $request;

        if (!isset($this->modelClass) && !isset($this->moduleName)) {
            throw new \Exception('Missing modelClass or moduleName in ' . $this::class);
        }

        $this->modelClass = $this->modelClass ?? getModelByModuleName($this->moduleName);
        $this->modelName = $this->getModelName();
        $this->namespace = $this->getNamespace();
        $this->repository = $this->getRepository();
        $this->viewPrefix = $this->getViewPrefix();
        $this->modelTitle = $this->getModelTitle();
        $this->labels = array_merge($this->defaultLabels, $this->labels);
        $this->middleware(function ($request, $next) {
            $this->user = auth('twill_users')->user();

            return $next($request);
        });

        /*
         * Default filters for the index view
         * By default, the search field will run a like query on the title field
         */
        if (!isset($this->defaultFilters)) {
            $this->defaultFilters = [
                'search' => ($this->moduleHas('translations') ? '' : '%') . $this->titleColumnKey,
            ];
        }

        /*
         * Apply any filters that are selected by default
         */
        $this->applyFiltersDefaultOptions();
    }

    /**
     * $type can be index or browser
     */
    private function getTableColumns(string $type): TableColumns
    {
        if ($type === 'index') {
            return $this->getIndexTableColumns();
        }
        return $this->getBrowserTableColumns();
    }

    protected function getBrowserTableColumns(): TableColumns
    {
        $columns = TableColumns::make();

        if ($this->browserColumns) {
            $this->handleLegacyColumns($columns, $this->browserColumns);
        } else {
            if ($this->moduleHas('medias')) {
                $columns->add(
                    Image::make()
                        ->field('thumbnail')
                        ->rounded()
                        ->title(twillTrans('Image'))
                );
            }

            $columns->add(
                Text::make()
                    ->field($this->titleColumnKey)
                    ->linkCell(function (Model $model) {
                        if ($this->getIndexOption('edit', $model)) {
                            return TwillRoutes::getModelRoute(
                                $this->modelClass,
                                TwillRouteActions::EDIT,
                                [$model->id]
                            );
                        }
                    })
            );
        }

        return $columns;
    }

    protected function getIndexTableColumns(): TableColumns
    {
        $columns = TableColumns::make();

        if ($this->getIndexOption('publish')) {
            $columns->add(
                PublishStatus::make()
                    ->title(twillTrans('twill::lang.listing.columns.published'))
                    ->sortable()
                    ->optional()
            );
        }

        // Consume Deprecated data.
        if ($this->indexColumns) {
            $this->handleLegacyColumns($columns, $this->indexColumns);
        } else {
            $columns->add(
                Text::make()
                    ->field($this->titleColumnKey)
                    ->linkCell(function (Model $model) {
                        if ($this->getIndexOption('edit', $model)) {
                            return TwillRoutes::getModelRoute(
                                $this->modelClass,
                                TwillRouteActions::EDIT,
                                [$model->id]
                            );
                        }
                    })
            );
        }

        // Add default columns.
        if ($this->getIndexOption('showImage')) {
            $columns->add(
                Image::make()
                    ->field('thumbnail')
                    ->rounded()
                    ->title(twillTrans('Image'))
            );
        }

        if ($this->getIndexOption('feature')) {
            $columns->add(
                Boolean::make()
                    ->field('featured')
                    ->title(twillTrans('twill::lang.listing.columns.featured'))
            );
        }

        if ($this->getIndexOption('includeScheduledInList') && $this->repository->isFillable('publish_start_date')) {
            $columns->add(
                ScheduledStatus::make()
                    ->title(twillTrans('twill::lang.listing.columns.published'))
                    ->optional()
            );
        }

        if ($this->moduleHas('translations') && count(getLocales()) > 1) {
            $columns->add(
                Languages::make()
                    ->title(twillTrans('twill::lang.listing.languages'))
                    ->optional()
            );
        }

        return $columns;
    }

    private function handleLegacyColumns(TableColumns $columns, array $items): void
    {
        foreach ($items as $key => $indexColumn) {
            if ($indexColumn['nested'] ?? false) {
                $columns->add(
                    NestedData::make()
                        ->title($indexColumn['title'] ?? null)
                        ->field($indexColumn['nested'])
                        ->sortKey($indexColumn['sortKey'] ?? null)
                        ->sortable($indexColumn['sort'] ?? false)
                        ->optional($indexColumn['optional'] ?? false)
                        ->linkCell(function (Model $model) use ($indexColumn) {
                            $module = Str::singular(last(explode('.', $this->getModuleName())));

                            return moduleRoute(
                                "{$this->getModuleName()}.{$indexColumn['nested']}",
                                $this->routePrefix,
                                'index',
                                [$module => $this->getItemIdentifier($model)]
                            );
                        })
                );
            } elseif ($indexColumn['relatedBrowser'] ?? false) {
                $columns->add(
                    Browser::make()
                        ->title($indexColumn['title'])
                        ->field($indexColumn['field'] ?? $key)
                        ->sortKey($indexColumn['sortKey'] ?? null)
                        ->optional($indexColumn['optional'] ?? false)
                        ->browser($indexColumn['relatedBrowser'])
                );
            } elseif ($indexColumn['relationship'] ?? false) {
                $columns->add(
                    Relation::make()
                        ->title($indexColumn['title'])
                        ->field($indexColumn['field'] ?? $key)
                        ->sortKey($indexColumn['sortKey'] ?? null)
                        ->optional($indexColumn['optional'] ?? false)
                        ->relation($indexColumn['relationship'])
                );
            } elseif ($indexColumn['present'] ?? false) {
                $columns->add(
                    Presenter::make()
                        ->title($indexColumn['title'])
                        ->field($indexColumn['field'] ?? $key)
                        ->sortKey($indexColumn['sortKey'] ?? null)
                        ->optional($indexColumn['optional'] ?? false)
                        ->sortable($indexColumn['sort'] ?? false)
                );
            } else {
                $columns->add(
                    Text::make()
                        ->title($indexColumn['title'] ?? null)
                        ->field($indexColumn['field'] ?? $key)
                        ->sortKey($indexColumn['sortKey'] ?? null)
                        ->optional($indexColumn['optional'] ?? false)
                        ->sortable($indexColumn['sort'] ?? false)
                );
            }
        }
    }

    /**
     * Match an option name to a gate name if needed, then authorize it.
     *
     * @return void
     */
    protected function authorizeOption($option, $arguments = [])
    {
        $gate = $this->authorizableOptions[$option] ?? $option;

        $this->authorize($gate, $arguments);
    }

    /**
     * @return void
     * @deprecated To be removed in Twill 3.0
     * @todo: Check this.
     */
    protected function setMiddlewarePermission()
    {
        $this->middleware('can:list', ['only' => ['index', 'show']]);
        $this->middleware('can:edit', ['only' => ['store', 'edit', 'update']]);
        $this->middleware('can:duplicate', ['only' => ['duplicate']]);
        $this->middleware('can:publish', ['only' => ['publish', 'feature', 'bulkPublish', 'bulkFeature']]);
        $this->middleware('can:reorder', ['only' => ['reorder']]);
        $this->middleware(
            'can:delete',
            [
                'only' => [
                    'destroy',
                    'bulkDelete',
                    'restore',
                    'bulkRestore',
                    'forceDelete',
                    'bulkForceDelete',
                    'restoreRevision',
                ],
            ]
        );
    }

    /**
     * @param Request $request
     * @return string|int|null
     */
    protected function getParentModuleIdFromRequest(Request $request)
    {
        $moduleParts = explode('.', $this->getModuleName());

        if (count($moduleParts) > 1) {
            $parentModule = Str::singular($moduleParts[count($moduleParts) - 2]);

            return $request->route()->parameters()[$parentModule];
        }

        return null;
    }

    /**
     * @param int|null $parentModuleId
     * @return array|\Illuminate\View\View
     */
    public function index($parentModuleId = null)
    {
        $this->authorizeOption('list', $this->getModuleName());

        $parentModuleId = $this->getParentModuleIdFromRequest($this->request) ?? $parentModuleId;

        $this->submodule = isset($parentModuleId);
        $this->submoduleParentId = $parentModuleId;

        $indexData = $this->getIndexData(
            $this->submodule ? [
                $this->getParentModuleForeignKey() => $this->submoduleParentId,
            ] : []
        );

        if ($this->request->ajax()) {
            return $indexData + ['replaceUrl' => true];
        }

        if ($this->request->has('openCreate') && $this->request->get('openCreate')) {
            $indexData += ['openCreate' => true];
        }

        $view = Collection::make([
            "$this->viewPrefix.index",
            "twill::{$this->getModuleName()}.index",
            'twill::layouts.listing',
        ])->first(function ($view) {
            return View::exists($view);
        });

        return View::make($view, $indexData);
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function browser()
    {
        return Response::json($this->getBrowserData());
    }

    /**
     * @param int|null $parentModuleId
     * @return \Illuminate\Http\JsonResponse
     */
    public function store($parentModuleId = null)
    {
        $this->authorizeOption('create', $this->getModuleName());

        $parentModuleId = $this->getParentModuleIdFromRequest($this->request) ?? $parentModuleId;

        $input = $this->validateFormRequest()->all();
        $optionalParent = $parentModuleId ? [$this->getParentModuleForeignKey() => $parentModuleId] : [];

        if (isset($input['cmsSaveType']) && $input['cmsSaveType'] === 'cancel') {
            return $this->respondWithRedirect(
                TwillRoutes::getModelRoute($this->modelClass, TwillRouteActions::CREATE)
            );
        }

        $item = $this->repository->create($input + $optionalParent);

        activity()->performedOn($item)->log('created');

        $this->fireEvent($input);

        Session::put($this->getModuleName() . '_retain', true);

        if ($this->getIndexOption('editInModal')) {
            return $this->respondWithSuccess(twillTrans('twill::lang.publisher.save-success'));
        }

        if (isset($input['cmsSaveType']) && Str::endsWith($input['cmsSaveType'], '-close')) {
            return $this->respondWithRedirect($this->getBackLink());
        }

        if (isset($input['cmsSaveType']) && Str::endsWith($input['cmsSaveType'], '-new')) {
            return $this->respondWithRedirect(
                TwillRoutes::getModelRoute($this->modelClass, TwillRouteActions::CREATE)
            );
        }

        return $this->respondWithRedirect(
            TwillRoutes::getModelRoute(
                $this->modelClass,
                TwillRouteActions::EDIT,
                [Str::singular(last(explode('.', $this->getModuleName()))) => $this->getItemIdentifier($item)]
            )
        );
    }

    public function show(int $id, ?int $submoduleId = null): RedirectResponse
    {
        if ($this->getIndexOption('editInModal')) {
            return Redirect::to(TwillRoutes::getModelRoute($this->modelClass, TwillRouteActions::INDEX));
        }

        return $this->redirectToForm($this->getParentModuleIdFromRequest($this->request) ?? $submoduleId ?? $id);
    }

    /**
     * @param int $id
     * @param int|null $submoduleId
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse|\Illuminate\View\View
     */
    public function edit($id, $submoduleId = null)
    {
        $params = $this->request->route()->parameters();

        $this->submodule = count($params) > 1;
        $this->submoduleParentId = $this->submodule
            ? $this->getParentModuleIdFromRequest($this->request) ?? $id
            : head($params);

        $id = last($params);

        $item = $this->repository->getById($submoduleId ?? $id);
        $this->authorizeOption('edit', $item);

        if ($this->getIndexOption('editInModal')) {
            return $this->request->ajax()
                ? Response::json($this->modalFormData($id))
                : Redirect::to(TwillRoutes::getModelRoute($this->modelClass, TwillRouteActions::INDEX));
        }

        $this->setBackLink();

        $controllerForm = $this->getForm($this->repository->getById($id));

        if ($controllerForm->isNotEmpty()) {
            $view = 'twill::layouts.form';
        } else {
            $view = Collection::make([
                "$this->viewPrefix.form",
                "twill::{$this->getModuleName()}.form",
                'twill::layouts.form',
            ])->first(function ($view) {
                return View::exists($view);
            });
        }

        View::share('form', $this->form($id));
        return View::make($view, $this->form($id))->with(
            'renderFields',
            $this->getForm($this->repository->getById($id))
        );
    }

    /**
     * @param int $parentModuleId
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse|\Illuminate\View\View
     */
    public function create($parentModuleId = null)
    {
        if (!$this->getIndexOption('skipCreateModal')) {
            return Redirect::to(
                TwillRoutes::getModelRoute($this->modelClass, TwillRouteActions::INDEX, ['openCreate' => true])
            );
        }

        $parentModuleId = $this->getParentModuleIdFromRequest($this->request) ?? $parentModuleId;

        $this->submodule = isset($parentModuleId);
        $this->submoduleParentId = $parentModuleId;

        $view = Collection::make([
            "$this->viewPrefix.form",
            "twill::{$this->getModuleName()}.form",
            'twill::layouts.form',
        ])->first(function ($view) {
            return View::exists($view);
        });

        View::share('form', $this->form(null));
        return View::make($view, $this->form(null));
    }

    /**
     * @param int $id
     * @param int|null $submoduleId
     * @return \Illuminate\Http\JsonResponse
     */
    public function update($id, $submoduleId = null)
    {
        $params = $this->request->route()->parameters();

        $submoduleParentId = $this->getParentModuleIdFromRequest($this->request) ?? $id;
        $this->submodule = $submoduleParentId;
        $this->submoduleParentId = $submoduleParentId;

        $id = last($params);

        $item = $this->repository->getById($id);

        $this->authorizeOption('edit', $item);

        $input = $this->request->all();

        if (isset($input['cmsSaveType']) && $input['cmsSaveType'] === 'cancel') {
            return $this->respondWithRedirect(
                TwillRoutes::getModelRoute(
                    $this->modelClass,
                    TwillRouteActions::EDIT,
                    [Str::singular($this->getModuleName()) => $id]
                )
            );
        }

        $formRequest = $this->validateFormRequest();

        $this->repository->update($id, $formRequest->all());

        activity()->performedOn($item)->log('updated');

        $this->fireEvent();

        if (isset($input['cmsSaveType'])) {
            if (Str::endsWith($input['cmsSaveType'], '-close')) {
                return $this->respondWithRedirect($this->getBackLink());
            }

            if (Str::endsWith($input['cmsSaveType'], '-new')) {
                if ($this->getIndexOption('skipCreateModal')) {
                    return $this->respondWithRedirect(
                        TwillRoutes::getModelRoute($this->modelClass, TwillRouteActions::CREATE)
                    );
                }

                return $this->respondWithRedirect(
                    TwillRoutes::getModelRoute($this->modelClass, TwillRouteActions::INDEX, ['openCreate' => true])
                );
            }

            if ($input['cmsSaveType'] === 'restore') {
                Session::flash('status', twillTrans('twill::lang.publisher.restore-success'));

                return $this->respondWithRedirect(
                    TwillRoutes::getModelRoute(
                        $this->modelClass,
                        TwillRouteActions::EDIT,
                        [Str::singular($this->getModuleName()) => $id]
                    )
                );
            }
        }

        if ($this->moduleHas('revisions')) {
            return Response::json([
                'message' => twillTrans('twill::lang.publisher.save-success'),
                'variant' => FlashLevel::SUCCESS,
                'revisions' => $item->revisionsArray(),
            ]);
        }

        return $this->respondWithSuccess(twillTrans('twill::lang.publisher.save-success'));
    }

    /**
     * @param int $id
     * @return \Illuminate\View\View
     */
    public function preview($id)
    {
        if ($this->request->has('revisionId')) {
            $item = $this->repository->previewForRevision($id, $this->request->get('revisionId'));
        } else {
            $formRequest = $this->validateFormRequest();
            $item = $this->repository->preview($id, $formRequest->all());
        }

        if ($this->request->has('activeLanguage')) {
            App::setLocale($this->request->get('activeLanguage'));
        }

        $previewView = $this->previewView ?? (Config::get('twill.frontend.views_path', 'site') . '.' . Str::singular(
                    $this->getModuleName()
                ));

        return View::exists($previewView) ? View::make(
            $previewView,
            array_replace([
                'item' => $item,
            ], $this->previewData($item))
        ) : View::make('twill::errors.preview', [
            'moduleName' => Str::singular($this->getModuleName()),
        ]);
    }

    /**
     * @param int $id
     * @return \Illuminate\View\View
     */
    public function restoreRevision($id)
    {
        if ($this->request->has('revisionId')) {
            $item = $this->repository->previewForRevision($id, $this->request->get('revisionId'));
            $item[$this->identifierColumnKey] = $id;
            $item->cmsRestoring = true;
        } else {
            throw new NotFoundHttpException();
        }

        $this->setBackLink();

        $view = Collection::make([
            "$this->viewPrefix.form",
            "twill::{$this->getModuleName()}.form",
            'twill::layouts.form',
        ])->first(function ($view) {
            return View::exists($view);
        });

        $revision = $item->revisions()->where('id', $this->request->get('revisionId'))->first();
        $date = $revision->created_at->toDayDateTimeString();

        Session::flash(
            'restoreMessage',
            twillTrans('twill::lang.publisher.restore-message', ['user' => $revision->byUser, 'date' => $date])
        );

        View::share('form', $this->form($id, $item));
        return View::make($view, $this->form($id, $item));
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function publish()
    {
        try {
            if ($this->repository->updateBasic($this->request->get('id'), [
                'published' => !$this->request->get('active'),
            ])) {
                activity()->performedOn(
                    $this->repository->getById($this->request->get('id'))
                )->log(
                    ($this->request->get('active') ? 'un' : '') . 'published'
                );

                $this->fireEvent();

                if ($this->request->get('active')) {
                    return $this->respondWithSuccess(
                        twillTrans('twill::lang.listing.publish.unpublished', ['modelTitle' => $this->modelTitle])
                    );
                } else {
                    return $this->respondWithSuccess(
                        twillTrans('twill::lang.listing.publish.published', ['modelTitle' => $this->modelTitle])
                    );
                }
            }
        } catch (\Exception $e) {
            Log::error($e);
        }

        return $this->respondWithError(
            twillTrans('twill::lang.listing.publish.error', ['modelTitle' => $this->modelTitle])
        );
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function bulkPublish()
    {
        try {
            if ($this->repository->updateBasic(explode(',', $this->request->get('ids')), [
                'published' => $this->request->get('publish'),
            ])) {
                $this->fireEvent();
                if ($this->request->get('publish')) {
                    return $this->respondWithSuccess(
                        twillTrans('twill::lang.listing.bulk-publish.published', ['modelTitle' => $this->modelTitle])
                    );
                } else {
                    return $this->respondWithSuccess(
                        twillTrans('twill::lang.listing.bulk-publish.unpublished', ['modelTitle' => $this->modelTitle])
                    );
                }
            }
        } catch (\Exception $e) {
            Log::error($e);
        }

        return $this->respondWithError(
            twillTrans('twill::lang.listing.bulk-publish.error', ['modelTitle' => $this->modelTitle])
        );
    }

    /**
     * @param int $id
     * @param int|null $submoduleId
     * @return \Illuminate\Http\JsonResponse
     */
    public function duplicate($id, $submoduleId = null)
    {
        $params = $this->request->route()->parameters();

        // @todo: Check this as it overwrites the argument.
        $id = last($params);

        $item = $this->repository->getById($id);
        if ($newItem = $this->repository->duplicate($id, $this->titleColumnKey)) {
            $this->fireEvent();
            activity()->performedOn($item)->log('duplicated');

            return Response::json([
                'message' => twillTrans('twill::lang.listing.duplicate.success', ['modelTitle' => $this->modelTitle]),
                'variant' => FlashLevel::SUCCESS,
                'redirect' => TwillRoutes::getModelRoute(
                    $this->modelClass,
                    TwillRouteActions::EDIT,
                    array_filter([Str::singular($this->getModuleName()) => $newItem->id])
                ),
            ]);
        }

        return $this->respondWithError(
            twillTrans('twill::lang.listing.duplicate.error', ['modelTitle' => $this->modelTitle])
        );
    }

    /**
     * @param int $id
     * @param int|null $submoduleId
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id, $submoduleId = null)
    {
        $params = $this->request->route()->parameters();

        // @todo: Check this as it overwrites the argument.
        $id = last($params);

        $item = $this->repository->getById($id);
        if ($this->repository->delete($id)) {
            $this->fireEvent();
            activity()->performedOn($item)->log('deleted');

            return $this->respondWithSuccess(
                twillTrans('twill::lang.listing.delete.success', ['modelTitle' => $this->modelTitle])
            );
        }

        return $this->respondWithError(
            twillTrans('twill::lang.listing.delete.error', ['modelTitle' => $this->modelTitle])
        );
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function bulkDelete()
    {
        if ($this->repository->bulkDelete(explode(',', $this->request->get('ids')))) {
            $this->fireEvent();

            return $this->respondWithSuccess(
                twillTrans('twill::lang.listing.bulk-delete.success', ['modelTitle' => $this->modelTitle])
            );
        }

        return $this->respondWithError(
            twillTrans('twill::lang.listing.bulk-delete.error', ['modelTitle' => $this->modelTitle])
        );
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function forceDelete()
    {
        if ($this->repository->forceDelete($this->request->get('id'))) {
            $this->fireEvent();

            return $this->respondWithSuccess(
                twillTrans('twill::lang.listing.force-delete.success', ['modelTitle' => $this->modelTitle])
            );
        }

        return $this->respondWithError(
            twillTrans('twill::lang.listing.force-delete.error', ['modelTitle' => $this->modelTitle])
        );
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function bulkForceDelete()
    {
        if ($this->repository->bulkForceDelete(explode(',', $this->request->get('ids')))) {
            $this->fireEvent();

            return $this->respondWithSuccess(
                twillTrans('twill::lang.listing.bulk-force-delete.success', ['modelTitle' => $this->modelTitle])
            );
        }

        return $this->respondWithError(
            twillTrans('twill::lang.listing.bulk-force-delete.error', ['modelTitle' => $this->modelTitle])
        );
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function restore()
    {
        if ($this->repository->restore($this->request->get('id'))) {
            $this->fireEvent();
            activity()->performedOn($this->repository->getById($this->request->get('id')))->log('restored');

            return $this->respondWithSuccess(
                twillTrans('twill::lang.listing.restore.success', ['modelTitle' => $this->modelTitle])
            );
        }

        return $this->respondWithError(
            twillTrans('twill::lang.listing.restore.error', ['modelTitle' => $this->modelTitle])
        );
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function bulkRestore()
    {
        if ($this->repository->bulkRestore(explode(',', $this->request->get('ids')))) {
            $this->fireEvent();

            return $this->respondWithSuccess(
                twillTrans('twill::lang.listing.bulk-restore.success', ['modelTitle' => $this->modelTitle])
            );
        }

        return $this->respondWithError(
            twillTrans('twill::lang.listing.bulk-restore.error', ['modelTitle' => $this->modelTitle])
        );
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function feature()
    {
        if (($id = $this->request->get('id'))) {
            $featuredField = $this->request->get('featureField') ?? $this->featureField;
            $featured = !$this->request->get('active');

            if ($this->repository->isUniqueFeature()) {
                if ($featured) {
                    $this->repository->updateBasic(null, [$featuredField => false]);
                    $this->repository->updateBasic($id, [$featuredField => $featured]);
                }
            } else {
                $this->repository->updateBasic($id, [$featuredField => $featured]);
            }

            activity()->performedOn(
                $this->repository->getById($id)
            )->log(
                ($this->request->get('active') ? 'un' : '') . 'featured'
            );

            $this->fireEvent();

            if ($this->request->get('active')) {
                return $this->respondWithSuccess(
                    twillTrans('twill::lang.listing.featured.unfeatured', ['modelTitle' => $this->modelTitle])
                );
            } else {
                return $this->respondWithSuccess(
                    twillTrans('twill::lang.listing.featured.featured', ['modelTitle' => $this->modelTitle])
                );
            }
        }

        return $this->respondWithError(
            twillTrans('twill::lang.listing.featured.error', ['modelTitle' => $this->modelTitle])
        );
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function bulkFeature()
    {
        if (($ids = explode(',', $this->request->get('ids')))) {
            $featuredField = $this->request->get('featureField') ?? $this->featureField;
            $featured = $this->request->get('feature') ?? true;
            // we don't need to check if unique feature since bulk operation shouldn't be allowed in this case
            $this->repository->updateBasic($ids, [$featuredField => $featured]);
            $this->fireEvent();

            if ($this->request->get('feature')) {
                return $this->respondWithSuccess(
                    twillTrans('twill::lang.listing.bulk-featured.featured', ['modelTitle' => $this->modelTitle])
                );
            } else {
                return $this->respondWithSuccess(
                    twillTrans('twill::lang.listing.bulk-featured.unfeatured', ['modelTitle' => $this->modelTitle])
                );
            }
        }

        return $this->respondWithError(
            twillTrans('twill::lang.listing.bulk-featured.error', ['modelTitle' => $this->modelTitle])
        );
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function reorder()
    {
        if ($values = $this->request->get('ids', null)) {
            $this->repository->setNewOrder($values);
            $this->fireEvent();

            return $this->respondWithSuccess(
                twillTrans('twill::lang.listing.reorder.success', ['modelTitle' => $this->modelTitle])
            );
        }

        return $this->respondWithError(
            twillTrans('twill::lang.listing.reorder.error', ['modelTitle' => $this->modelTitle])
        );
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function tags()
    {
        $query = $this->request->input('q');
        $tags = $this->repository->getTags($query);

        return Response::json([
            'items' => $tags->map(function ($tag) {
                return $tag->name;
            }),
        ], 200);
    }

    public function additionalTableActions(): array
    {
        return [];
    }

    protected function getIndexData(array $prependScope = []): array
    {
        $scopes = $this->filterScope($prependScope);
        $items = $this->getIndexItems($scopes);

        $data = [
                'tableData' => $this->getIndexTableData($items),
                'tableColumns' => $this->getTableColumns('index')->toCmsArray(
                    request(),
                    $this->getIndexOption('reorder')
                ),
                'tableMainFilters' => $this->getIndexTableMainFilters($items),
                'filters' => json_decode($this->request->get('filter'), true) ?? [],
                'hiddenFilters' => array_keys(Arr::except($this->filters, array_keys($this->defaultFilters))),
                'filterLinks' => $this->filterLinks ?? [],
                'maxPage' => method_exists($items, 'lastPage') ? $items->lastPage() : 1,
                'defaultMaxPage' => method_exists($items, 'total') ? ceil($items->total() / $this->perPage) : 1,
                'offset' => method_exists($items, 'perPage') ? $items->perPage() : count($items),
                'defaultOffset' => $this->perPage,
            ] + $this->getIndexUrls($this->getModuleName());

        $baseUrl = $this->getPermalinkBaseUrl();

        $options = [
            'moduleName' => $this->getModuleName(),
            'skipCreateModal' => $this->getIndexOption('skipCreateModal'),
            'reorder' => $this->getIndexOption('reorder'),
            'create' => $this->getIndexOption('create'),
            'duplicate' => $this->getIndexOption('duplicate'),
            'translate' => $this->moduleHas('translations'),
            'translateTitle' => $this->titleIsTranslatable(),
            'permalink' => $this->getIndexOption('permalink'),
            'bulkEdit' => $this->getIndexOption('bulkEdit'),
            'titleFormKey' => $this->titleFormKey ?? $this->titleColumnKey,
            'baseUrl' => $baseUrl,
            'permalinkPrefix' => $this->getPermalinkPrefix($baseUrl),
            'additionalTableActions' => $this->additionalTableActions(),
        ];

        return array_replace_recursive($data + $options, $this->indexData($this->request));
    }

    /**
     * @param Request $request
     * @return array
     */
    protected function indexData($request)
    {
        return [];
    }

    /**
     * @param array $scopes
     * @param bool $forcePagination
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function getIndexItems($scopes = [], $forcePagination = false)
    {
        if (config('twill.enabled.permissions-management') && isPermissionableModule($this->getModuleName())) {
            $scopes = $scopes + ['accessible' => true];
        }

        return $this->transformIndexItems(
            $this->repository->get(
                $this->indexWith,
                $scopes,
                $this->orderScope(),
                $this->request->get('offset') ?? $this->perPage ?? 50,
                $forcePagination
            )
        );
    }

    /**
     * @param \Illuminate\Database\Eloquent\Collection $items
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function transformIndexItems($items)
    {
        return $items;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Collection|\A17\Twill\Models\Model[] $items
     */
    protected function getIndexTableData(Collection|LengthAwarePaginator $items): array
    {
        $translated = $this->moduleHas('translations');

        return $items->map(function (BaseModel $item) use ($translated) {
            $columnsData = $this->getTableColumns('index')->getArrayForModel($item);

            $itemIsTrashed = method_exists($item, 'trashed') && $item->trashed();

            $itemId = $this->getItemIdentifier($item);

            $editUrl = $this->getIndexOption('edit', $item) ?
                TwillRoutes::getModelRoute($this->modelClass, TwillRouteActions::EDIT, [$itemId]) :
                null;

            $duplicateUrl = $this->getIndexOption('duplicate', $item) ?
                TwillRoutes::getModelRoute($this->modelClass, TwillRouteActions::DUPLICATE, [$itemId]) :
                null;

            $destroyUrl = $this->getIndexOption('delete', $item) ?
                TwillRoutes::getModelRoute($this->modelClass, TwillRouteActions::DESTROY, [$itemId]) :
                null;

            return array_replace(
                [
                    'id' => $itemId,
                    'publish_start_date' => $item->publish_start_date,
                    'publish_end_date' => $item->publish_end_date,
                    'edit' => $editUrl,
                    'duplicate' => $duplicateUrl,
                    'delete' => $destroyUrl,
                ] + ($this->getIndexOption('editInModal') ? [
                    'editInModal' => TwillRoutes::getModelRoute($this->modelClass, TwillRouteActions::EDIT, [$itemId]),
                    'updateUrl' => TwillRoutes::getModelRoute($this->modelClass, TwillRouteActions::UPDATE, [$itemId]),
                ] : []) + ($this->getIndexOption('publish') && ($item->canPublish ?? true) ? [
                    'published' => $item->published,
                ] : []) + ($this->getIndexOption('feature', $item) && ($item->canFeature ?? true) ? [
                    'featured' => $item->{$this->featureField},
                ] : []) + (($this->getIndexOption('restore', $item) && $itemIsTrashed) ? [
                    'deleted' => true,
                ] : []) + (($this->getIndexOption('forceDelete') && $itemIsTrashed) ? [
                    'destroyable' => true,
                ] : []) + ($translated ? [
                    'languages' => $item->getActiveLanguages(),
                ] : []) + $columnsData,
                $this->indexItemData($item)
            );
        })->toArray();
    }

    /**
     * @param \A17\Twill\Models\Model $item
     * @return array
     */
    protected function indexItemData($item)
    {
        return [];
    }

    /**
     * @param \A17\Twill\Models\Model $item
     * @return int|string
     */
    protected function getItemIdentifier($item)
    {
        return $item->{$this->identifierColumnKey};
    }

    /**
     * @param \Illuminate\Database\Eloquent\Collection $items
     * @param array $scopes
     * @return array
     */
    protected function getIndexTableMainFilters($items, $scopes = [])
    {
        $statusFilters = [];

        $scope = ($this->submodule ? [
                $this->getParentModuleForeignKey() => $this->submoduleParentId,
            ] : []) + $scopes;

        $statusFilters[] = [
            'name' => twillTrans('twill::lang.listing.filter.all-items'),
            'slug' => 'all',
            'number' => $this->repository->getCountByStatusSlug('all', $scope),
        ];

        if ($this->moduleHas('revisions') && $this->getIndexOption('create')) {
            $statusFilters[] = [
                'name' => twillTrans('twill::lang.listing.filter.mine'),
                'slug' => 'mine',
                'number' => $this->repository->getCountByStatusSlug('mine', $scope),
            ];
        }

        if ($this->getIndexOption('publish')) {
            $statusFilters[] = [
                'name' => $this->getTransLabel('listing.filter.published'),
                'slug' => 'published',
                'number' => $this->repository->getCountByStatusSlug('published', $scope),
            ];
            $statusFilters[] = [
                'name' => $this->getTransLabel('listing.filter.draft'),
                'slug' => 'draft',
                'number' => $this->repository->getCountByStatusSlug('draft', $scope),
            ];
        }

        if ($this->getIndexOption('restore')) {
            $statusFilters[] = [
                'name' => twillTrans('twill::lang.listing.filter.trash'),
                'slug' => 'trash',
                'number' => $this->repository->getCountByStatusSlug('trash', $scope),
            ];
        }

        return $statusFilters;
    }

    protected function getIndexUrls(string $moduleName): array
    {
        return Collection::make([
            'create',
            'store',
            'publish',
            'bulkPublish',
            'restore',
            'bulkRestore',
            'forceDelete',
            'bulkForceDelete',
            'reorder',
            'feature',
            'bulkFeature',
            'bulkDelete',
        ])->mapWithKeys(function ($endpoint) {
            return [
                $endpoint . 'Url' => $this->getIndexOption($endpoint) ?
                    TwillRoutes::getModelRoute(
                        $this->modelClass,
                        $endpoint,
                        $this->submodule ? [$this->submoduleParentId] : [],
                    ) :
                    null,
            ];
        })->toArray();
    }

    /**
     * @param string $option
     * @return bool
     */
    protected function getIndexOption($option, $item = null)
    {
        return once(function () use ($option, $item) {
            $customOptionNamesMapping = [
                'store' => 'create',
            ];
            $option = array_key_exists(
                $option,
                $customOptionNamesMapping
            ) ? $customOptionNamesMapping[$option] : $option;
            $authorized = false;

            if (array_key_exists($option, $this->authorizableOptions)) {
                if (Str::endsWith($this->authorizableOptions[$option], '-module')) {
                    $authorized = $this->user->can($this->authorizableOptions[$option], $this->getModuleName());
                } elseif (Str::endsWith($this->authorizableOptions[$option], '-item')) {
                    $authorized = $item ?
                        $this->user->can($this->authorizableOptions[$option], $item) :
                        $this->user->can(
                            Str::replaceLast('-item', '-module', $this->authorizableOptions[$option]),
                            $this->getModuleName()
                        );
                }
            }

            return ($this->indexOptions[$option] ?? $this->defaultIndexOptions[$option] ?? false) && $authorized;
        });
    }

    /**
     * @param array $prependScope
     * @return array
     */
    protected function getBrowserData($prependScope = [])
    {
        if ($this->request->has('except')) {
            $prependScope['exceptIds'] = $this->request->get('except');
        }

        $forRepeater = $this->request->get('forRepeater', false) === 'true';

        $scopes = $this->filterScope($prependScope);
        $items = $this->getBrowserItems($scopes);
        $data = $this->getBrowserTableData($items, $forRepeater);

        return array_replace_recursive(['data' => $data], $this->indexData($this->request));
    }

    protected function getBrowserTableData(Collection|LengthAwarePaginator $items, bool $forRepeater = false): array
    {
        return $items->map(function (BaseModel $item) use ($forRepeater) {
            $repeaterFields = [];
            if ($forRepeater) {
                $translatedAttributes = $item->getTranslatedAttributes();
                foreach ($item->getFillable() as $field) {
                    if (in_array($field, $translatedAttributes, true)) {
                        $repeaterFields[$field] = $item->translatedAttribute($field);
                    } else {
                        $repeaterFields[$field] = $item->{$field};
                    }
                }
            }

            return $this->getTableColumns('browser')->getArrayForModelBrowser(
                $item,
                new TableDataContext(
                    $this->titleColumnKey,
                    $this->identifierColumnKey,
                    $this->getModuleName(),
                    $this->routePrefix,
                    $this->repository->getMorphClass(),
                    $this->moduleHas('medias'),
                    $repeaterFields
                )
            );
        })->toArray();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function getBrowserItems(array $scopes = [])
    {
        return $this->getIndexItems($scopes, true);
    }

    /**
     * @param array $prepend
     * @return array
     */
    protected function filterScope($prepend = [])
    {
        $scope = [];

        $requestFilters = $this->getRequestFilters();

        $this->filters = array_merge($this->filters, $this->defaultFilters);

        if (array_key_exists('status', $requestFilters)) {
            switch ($requestFilters['status']) {
                case 'published':
                    $scope['published'] = true;
                    break;
                case 'draft':
                    $scope['draft'] = true;
                    break;
                case 'trash':
                    $scope['onlyTrashed'] = true;
                    break;
                case 'mine':
                    $scope['mine'] = true;
                    break;
            }

            unset($requestFilters['status']);
        }

        foreach ($this->filters as $key => $field) {
            if (array_key_exists($key, $requestFilters)) {
                $value = $requestFilters[$key];
                if ($value == 0 || !empty($value)) {
                    // add some syntaxic sugar to scope the same filter on multiple columns
                    $fieldSplitted = explode('|', $field);
                    if (count($fieldSplitted) > 1) {
                        $requestValue = $requestFilters[$key];
                        Collection::make($fieldSplitted)->each(function ($scopeKey) use (&$scope, $requestValue) {
                            $scope[$scopeKey] = $requestValue;
                        });
                    } else {
                        $scope[$field] = $requestFilters[$key];
                    }
                }
            }
        }

        return $prepend + $scope;
    }

    /**
     * @return array
     */
    protected function getRequestFilters()
    {
        if ($this->request->has('search')) {
            return ['search' => $this->request->get('search')];
        }

        return json_decode($this->request->get('filter'), true) ?? [];
    }

    /**
     * @return void
     */
    protected function applyFiltersDefaultOptions()
    {
        if (!count($this->filtersDefaultOptions) || $this->request->has('search')) {
            return;
        }

        $filters = $this->getRequestFilters();

        foreach ($this->filtersDefaultOptions as $filterName => $defaultOption) {
            if (!isset($filters[$filterName])) {
                $filters[$filterName] = $defaultOption;
            }
        }

        $this->request->merge(['filter' => json_encode($filters)]);
    }

    protected function orderScope(): array
    {
        $orders = [];
        if ($this->request->has('sortKey') && $this->request->has('sortDir')) {
            if (($key = $this->request->get('sortKey')) === 'name') {
                $sortKey = $this->titleColumnKey;
            } elseif (!empty($key)) {
                $sortKey = $key;
            }

            if (isset($sortKey)) {
                /** @var \A17\Twill\Services\Listings\TableColumn $indexColumn */
                $indexColumn = $this->getIndexTableColumns()->first(function (TableColumn $column) use ($sortKey) {
                    return $column->getKey() === $sortKey;
                });
                $orders[$indexColumn?->getSortKey() ?? $sortKey] = $this->request->get('sortDir');
            }
        }

        // don't apply default orders if reorder is enabled
        $reorder = $this->getIndexOption('reorder');
        $defaultOrders = ($reorder ? [] : ($this->defaultOrders ?? []));

        return $orders + $defaultOrders;
    }

    protected function form(?int $id = null, ?Model $item = null): array
    {
        if (!$item && $id) {
            $item = $this->repository->getById($id, $this->formWith, $this->formWithCount);
        } elseif (!$item && !$id) {
            $item = $this->repository->newInstance();
        }

        $baseUrl = $item->urlWithoutSlug ?? $this->getPermalinkBaseUrl();
        $localizedPermalinkBase = $this->getLocalizedPermalinkBase();

        $itemId = $this->getItemIdentifier($item);

        if ($itemId) {
            $saveUrl = TwillRoutes::getModelRoute(
                $this->modelClass,
                TwillRouteActions::UPDATE,
                [$itemId],
            );
        } else {
            $saveUrl = TwillRoutes::getModelRoute(
                $this->modelClass,
                TwillRouteActions::STORE,
                [$this->submoduleParentId],
            );
        }

        $hasEditor = Config::get('twill.enabled.block-editor') && $this->moduleHas('blocks') && !$this->disableEditor;

        $data = [
            'item' => $item,
            'moduleName' => $this->getModuleName(),
            'routePrefix' => TwillRoutes::getRoutePrefixForModel($this->modelClass),
            'titleFormKey' => $this->titleFormKey ?? $this->titleColumnKey,
            'publish' => $item->canPublish ?? true,
            'publishDate24Hr' => Config::get('twill.publish_date_24h', false),
            'publishDateFormat' => Config::get('twill.publish_date_format'),
            'publishDateDisplayFormat' => Config::get('twill.publish_date_display_format'),
            'publishedLabel' => $this->getTransLabel('published'),
            'draftLabel' => $this->getTransLabel('draft'),
            'translate' => $this->moduleHas('translations'),
            'translateTitle' => $this->titleIsTranslatable(),
            'permalink' => $this->getIndexOption('permalink', $item),
            'createWithoutModal' => !$itemId && $this->getIndexOption('skipCreateModal'),
            'form_fields' => $this->repository->getFormFields($item),
            'baseUrl' => $baseUrl,
            'localizedPermalinkBase' => $localizedPermalinkBase,
            'permalinkPrefix' => $this->getPermalinkPrefix($baseUrl),
            'saveUrl' => $saveUrl,
            'editor' => $hasEditor,
            'blockPreviewUrl' => Route::has('twill.blocks.preview') ? URL::route('twill.blocks.preview') : '#',
            'availableRepeaters' => $this->getRepeaterList()->toJson(),
            'revisions' => $this->moduleHas('revisions') ? $item->revisionsArray() : null,
            'groupUserMapping' => $this->getGroupUserMapping(),
            'showPermissionFieldset' => $this->getShowPermissionFieldset($item),
        ];

        if ($itemId) {
            if (Route::has(
                TwillRoutes::getModelRouteName($this->modelClass, TwillRouteActions::PREVIEW)
            )) {
                $data['previewUrl'] = TwillRoutes::getModelRoute(
                    $this->modelClass,
                    TwillRouteActions::PREVIEW,
                    [$itemId],
                );
            }

            if (Route::has(
                TwillRoutes::getModelRouteName($this->modelClass, TwillRouteActions::RESTORE_REVISION)
            )) {
                $data['restoreUrl'] = TwillRoutes::getModelRoute(
                    $this->modelClass,
                    TwillRouteActions::RESTORE_REVISION,
                    [$itemId],
                );
            }
        }

        return array_replace_recursive($data, $this->formData($this->request));
    }

    protected function modalFormData(int $id): array
    {
        $item = $this->repository->getById($id, $this->formWith, $this->formWithCount);
        $fields = $this->repository->getFormFields($item);
        $data = [];

        if ($this->moduleHas('translations') && isset($fields['translations'])) {
            foreach ($fields['translations'] as $fieldName => $fieldValue) {
                $data['fields'][] = [
                    'name' => $fieldName,
                    'value' => $fieldValue,
                ];
            }

            $data['languages'] = $item->getActiveLanguages();

            unset($fields['translations']);
        }

        foreach ($fields as $fieldName => $fieldValue) {
            $data['fields'][] = [
                'name' => $fieldName,
                'value' => $fieldValue,
            ];
        }

        return array_replace_recursive($data, $this->formData($this->request));
    }

    protected function formData(Request $request): array
    {
        return [];
    }

    protected function previewData(Request $item): array
    {
        return [];
    }

    protected function validateFormRequest(): \A17\Twill\Http\Requests\Admin\Request
    {
        $unauthorizedFields = Collection::make($this->fieldsPermissions)->filter(function ($permission, $field) {
            return Auth::guard('twill_users')->user()->cannot($permission);
        })->keys();

        $unauthorizedFields->each(function ($field) {
            $this->request->offsetUnset($field);
        });

        return App::make($this->getFormRequestClass());
    }

    public function getFormRequestClass(): string
    {
        $prefix = '\Admin';
        if ($this->namespace !== 'A17\Twill') {
            $prefix = "\Twill";
        }

        $request = "$this->namespace\Http\Requests$prefix\\" . $this->modelName . 'Request';

        if (@class_exists($request)) {
            return $request;
        }

        return TwillCapsules::getCapsuleForModel($this->modelName)->getFormRequestClass();
    }

    /**
     * @return string
     */
    protected function getNamespace(): string
    {
        return $this->namespace ?? Config::get('twill.namespace');
    }

    /**
     * @return string
     */
    protected function getModulePermalinkBase()
    {
        $base = '';
        $moduleParts = explode('.', $this->getModuleName());

        foreach ($moduleParts as $index => $name) {
            if (array_key_last($moduleParts) !== $index) {
                $singularName = Str::singular($name);
                $modelClass = config('twill.namespace') . '\\Models\\' . Str::studly($singularName);

                if (!class_exists($modelClass)) {
                    $modelClass = TwillCapsules::getCapsuleForModel($name)->getModel();
                }

                $model = (new $modelClass())->findOrFail(request()->route()->parameter($singularName));
                $hasSlug = Arr::has(class_uses($modelClass), HasSlug::class);

                $base .= $name . '/' . ($hasSlug ? $model->slug : $model->id) . '/';
            } else {
                $base .= $name;
            }
        }

        return $base;
    }

    protected function getModelName(): string
    {
        $modelName = '';
        if ($this->getModuleName()) {
            $modelName = ucfirst(Str::singular($this->getModuleName()));
        }

        if ($this->modelClass) {
            $modelName = Str::afterLast($this->modelClass, '\\');
        }

        if ($modelName === '' && !isset($this->modelName)) {
            throw new \Exception('Missing modelClass on ' . self::class);
        }

        return $this->modelName ?? $modelName;
    }

    /**
     * @return \A17\Twill\Repositories\ModuleRepository
     */
    protected function getRepository()
    {
        return App::make($this->getRepositoryClass($this->modelName));
    }

    public function getRepositoryClass($model)
    {
        if (@class_exists($class = "$this->namespace\Repositories\\" . $model . 'Repository')) {
            return $class;
        }

        return TwillCapsules::getCapsuleForModel($model)->getRepositoryClass();
    }

    protected function getViewPrefix(): ?string
    {
        $prefix = "twill.{$this->getModuleName()}";

        if (view()->exists("$prefix.form")) {
            return $prefix;
        }

        try {
            return TwillCapsules::getCapsuleForModel($this->modelName)->getViewPrefix();
        } catch (NoCapsuleFoundException $e) {
            return null;
        }
    }

    /**
     * @return string
     */
    protected function getModelTitle()
    {
        return camelCaseToWords($this->modelName);
    }

    /**
     * @return string
     */
    protected function getParentModuleForeignKey()
    {
        $moduleParts = explode('.', $this->getModuleName());

        return Str::singular($moduleParts[count($moduleParts) - 2]) . '_id';
    }

    /**
     * @return string
     */
    protected function getPermalinkBaseUrl()
    {
        $appUrl = Config::get('app.url');

        if (blank(parse_url($appUrl)['scheme'] ?? null)) {
            $appUrl = $this->request->getScheme() . '://' . $appUrl;
        }

        return $appUrl . '/'
            . ($this->moduleHas('translations') ? '{language}/' : '')
            . ($this->moduleHas('revisions') ? '{preview}/' : '')
            . (empty($this->getLocalizedPermalinkBase()) ? ($this->permalinkBase ?? $this->getModulePermalinkBase(
                )) : '')
            . (((isset($this->permalinkBase) && empty($this->permalinkBase)) || !empty(
                $this->getLocalizedPermalinkBase()
                )) ? '' : '/');
    }

    /**
     * @return array
     */
    protected function getLocalizedPermalinkBase()
    {
        return [];
    }

    /**
     * @param string $baseUrl
     * @return string
     */
    protected function getPermalinkPrefix($baseUrl)
    {
        return rtrim(str_replace(['http://', 'https://', '{preview}/', '{language}/'], '', $baseUrl), '/') . '/';
    }

    protected function moduleHas(string $behavior): bool
    {
        return $this->repository->hasBehavior($behavior);
    }

    protected function titleIsTranslatable(): bool
    {
        return $this->repository->isTranslatable(
            $this->titleColumnKey
        );
    }

    protected function setBackLink(?string $back_link = null, array $params = []): void
    {
        if (!isset($back_link) && ($back_link = Session::get($this->getBackLinkSessionKey())) === null) {
            $back_link = $this->request->headers->get('referer') ?? TwillRoutes::getModelRoute(
                    $this->modelClass,
                    TwillRouteActions::INDEX,
                    $params
                );
        }

        if (!Session::get($this->getModuleName() . '_retain')) {
            Session::put($this->getBackLinkSessionKey(), $back_link);
        } else {
            Session::put($this->getModuleName() . '_retain', false);
        }
    }

    protected function getBackLink(?string $fallback = null, array $params = []): string
    {
        $back_link = Session::get($this->getBackLinkSessionKey(), $fallback);

        return $back_link ?? TwillRoutes::getModelRoute($this->modelClass, TwillRouteActions::INDEX, $params);
    }

    protected function getBackLinkSessionKey(): string
    {
        return $this->getModuleName() . ($this->submodule ? $this->submoduleParentId ?? '' : '') . '_back_link';
    }

    protected function redirectToForm(int $id, array $params = []): RedirectResponse
    {
        Session::put($this->getModuleName() . '_retain', true);

        return Redirect::to(
            TwillRoutes::getModelRoute(
                $this->modelClass,
                TwillRouteActions::EDIT,
                array_filter($params) + [Str::singular($this->getModuleName()) => $id],
            )
        );
    }

    protected function respondWithSuccess(string $message): JsonResponse
    {
        return $this->respondWithJson($message, FlashLevel::SUCCESS);
    }

    protected function respondWithRedirect(string $redirectUrl): JsonResponse
    {
        return Response::json([
            'redirect' => $redirectUrl,
        ]);
    }

    protected function respondWithError(string $message): JsonResponse
    {
        return $this->respondWithJson($message, FlashLevel::ERROR);
    }

    protected function respondWithJson(string $message, mixed $variant): JsonResponse
    {
        return Response::json([
            'message' => $message,
            'variant' => $variant,
        ]);
    }

    protected function getGroupUserMapping(): array
    {
        if (config('twill.enabled.permissions-management')) {
            return Group::with('users')->get()
                ->mapWithKeys(function ($group) {
                    return [$group->id => $group->users()->pluck('id')->toArray()];
                })->toArray();
        }

        return [];
    }

    protected function fireEvent(array $input = []): void
    {
        fireCmsEvent('cms-module.saved', $input);
    }

    protected function getShowPermissionFieldset($item): bool
    {
        if (config('twill.enabled.permissions-management')) {
            $permissionModuleName = isPermissionableModule(getModuleNameByModel($item));

            return $permissionModuleName && !strpos($permissionModuleName, '.');
        }

        return false;
    }

    /**
     * @return Collection|Block[]
     */
    public function getRepeaterList()
    {
        return TwillBlocks::getBlockCollection()->getRepeaters()->mapWithKeys(function (Block $repeater) {
            return [$repeater->name => $repeater->toList()];
        });
    }

    /**
     * Get translation key from labels array and attempts to return a translated string.
     */
    protected function getTransLabel(string $key, array $replace = []): string
    {
        return twillTrans(Arr::has($this->labels, $key) ? Arr::get($this->labels, $key) : $key, $replace);
    }

    public function getForm(\Illuminate\Database\Eloquent\Model $model): Form
    {
        return new Form();
    }

    public function getModuleName(): string
    {
        return getModuleNameByModel($this->modelClass);
    }
}
