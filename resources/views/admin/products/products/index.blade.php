@extends('adminlte::page')

@section('title', 'Equipments')

@section('plugins.Select2', true)

@section('content_header')
    <h1>Equipments Management</h1>
@stop

@section('content')
    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <i class="icon fas fa-check"></i> {{ session('success') }}
        </div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <i class="icon fas fa-ban"></i> {{ session('error') }}
        </div>
    @endif

    <div class="card">
        <div class="products-list-sticky">
        <div class="card-header">
            <h3 class="card-title">All Equipments</h3>
            <div class="card-tools">
                <button type="button" id="bulkEditBtn" class="btn btn-warning btn-sm" style="display: none; margin-right: 5px;">
                    <i class="fas fa-edit"></i> <span class="d-none d-lg-inline">Multi-Edit</span><span class="d-lg-none">Edit</span>
                </button>
                <button type="button" id="bulkVerifyBtn" class="btn btn-success btn-sm" style="display: none; margin-right: 5px;">
                    <i class="fas fa-check-circle"></i> <span class="d-none d-lg-inline">Verify Selected</span><span class="d-lg-none">Verify</span>
                </button>
                <button type="button" id="bulkEnrichBtn" class="btn btn-info btn-sm" style="display: none; margin-right: 5px;">
                    <i class="fas fa-robot"></i> <span class="d-none d-lg-inline">AI Enrich Selected</span><span class="d-lg-none">AI Enrich</span>
                </button>
                <button type="button" id="bulkDeleteBtn" class="btn btn-danger btn-sm" style="display: none; margin-right: 5px;">
                    <i class="fas fa-trash"></i> <span class="d-none d-lg-inline">Delete Selected</span><span class="d-lg-none">Delete</span>
                </button>
                <a href="{{ route('admin.products.create') }}" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus"></i> Add New Product
                </a>
            </div>
        </div>
            <div class="products-list-sticky__panel">
            <div id="productCatalogFilters" class="product-list-filters">
                <div class="product-list-filters__grid">
                    <div class="product-list-filters__field">
                        <label for="filterBrand">Brand</label>
                        <select id="filterBrand" class="form-control"></select>
                    </div>
                    <div class="product-list-filters__field">
                        <label for="filterCategory">Category</label>
                        <select id="filterCategory" class="form-control"></select>
                    </div>
                    <div class="product-list-filters__field">
                        <label for="filterSubCategory">Sub Category</label>
                        <select id="filterSubCategory" class="form-control"></select>
                    </div>
                    <div class="product-list-filters__actions">
                        <button type="button" id="clearProductFilters" class="btn btn-default">
                            <i class="fas fa-times"></i><span>Clear Filters</span>
                        </button>
                    </div>
                </div>
                <label class="product-list-filters__check" for="filterUnverified">
                    <input type="checkbox" id="filterUnverified" name="filter_unverified">
                    <span>Show only unverified products</span>
                </label>
            </div>
            <div id="selectedProductsSummary" class="alert alert-info py-2" style="display: none;">
                <strong id="selectedProductsSummaryText"></strong>
            </div>
            </div>
        </div>
        <div class="card-body products-list-table">
            <div id="productsAllBrowser" class="products-all-browser" style="display: none;" aria-live="polite">
                <p id="productsAllStatus" class="mb-2"></p>
                <div class="products-all-browser__controls">
                    <button type="button" class="btn btn-default btn-sm" id="productsAllFirst">First</button>
                    <button type="button" class="btn btn-default btn-sm" id="productsAllPrev">Previous 100</button>
                    <button type="button" class="btn btn-default btn-sm" id="productsAllNext">Next 100</button>
                    <button type="button" class="btn btn-default btn-sm" id="productsAllLast">Last</button>
                    <label class="mb-0" for="productsAllJump">Jump to row
                        <input type="number" id="productsAllJump" class="form-control form-control-sm d-inline-block" min="1" step="100" style="width: 7rem;">
                    </label>
                    <button type="button" class="btn btn-default btn-sm" id="productsAllGo">Go</button>
                </div>
                <p class="text-muted mb-0 mt-2">
                    <small>Search, filters, and sorting apply to the full catalog. When more than {{ (int) \App\Http\Controllers\Admin\ProductController::PRODUCT_LIST_MAX_PAGE_SIZE }} products match, All loads them {{ (int) \App\Http\Controllers\Admin\ProductController::PRODUCT_LIST_MAX_PAGE_SIZE }} at a time. Lists of that size or smaller load in one request. Products you check stay selected while you move between batches, and bulk actions use that selection.</small>
                </p>
            </div>
            <div id="productsListError" class="alert alert-danger" style="display: none;" role="alert">
                <span id="productsListErrorMessage">Error loading products data.</span>
                <button type="button" class="btn btn-sm btn-outline-danger ml-2" id="productsListRetry">Retry</button>
            </div>
            <div id="productsTableScroll">
            <table id="productsTable" class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th style="width: 40px;">
                            <input type="checkbox" id="selectAll" title="Select All">
                        </th>
                        <th>ID</th>
                        <th>Brand</th>
                        <th>Model</th>
                        <th>Category</th>
                        <th>Sub-Category</th>
                        <th>PSM Code</th>
                        <th>Replacement Price</th>
                        <th>Dimensions & Weight</th>
                        <th>Verified Status</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Data will be loaded via AJAX -->
                </tbody>
            </table>
            <div id="productsAllSentinel" style="display: none;" aria-hidden="true"></div>
            </div>
        </div>
    </div>

    <!-- AI Enrichment Results Modal -->
    <div class="modal fade" id="enrichResultsModal" tabindex="-1" role="dialog" aria-labelledby="enrichResultsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="enrichResultsModalLabel">AI Enrichment Results</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div id="enrichResultsSummary" class="alert alert-info"></div>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped mb-0">
                            <thead>
                                <tr>
                                    <th>Product ID</th>
                                    <th>Product Name</th>
                                    <th>Outcome</th>
                                    <th>Status</th>
                                    <th>Message</th>
                                </tr>
                            </thead>
                            <tbody id="enrichResultsBody"></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bulk Edit Modal -->
    <div class="modal fade" id="bulkEditModal" tabindex="-1" role="dialog" aria-labelledby="bulkEditModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="bulkEditModalLabel">Bulk Edit</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div id="bulkEditFormStep">
                        <p class="mb-3">
                            These changes will be applied to all <strong id="bulkEditCount">0</strong> selected products.
                            Only fields you choose to change will be updated.
                        </p>
                        <div class="form-group">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="bulkEditChangeCategory">
                                <label class="form-check-label" for="bulkEditChangeCategory">Change Category</label>
                            </div>
                            <select class="form-control" id="bulkEditCategory" disabled>
                                <option value="">-- Select Category --</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="bulkEditChangeSubCategory">
                                <label class="form-check-label" for="bulkEditChangeSubCategory">Change Sub-Category</label>
                            </div>
                            <select class="form-control" id="bulkEditSubCategory" disabled>
                                <option value="">-- Select Sub-Category --</option>
                            </select>
                            <small class="form-text text-muted">Sub-categories follow the selected category when Category is being changed.</small>
                        </div>
                        <div class="form-group mb-0">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="bulkEditChangeBrand">
                                <label class="form-check-label" for="bulkEditChangeBrand">Change Brand</label>
                            </div>
                            <select class="form-control" id="bulkEditBrand" disabled>
                                <option value="">-- Select Brand --</option>
                            </select>
                        </div>
                        <div id="bulkEditFormError" class="alert alert-danger mt-3 mb-0" style="display: none;"></div>
                    </div>
                    <div id="bulkEditConfirmStep" style="display: none;">
                        <p class="mb-2"><strong id="bulkEditConfirmQuestion"></strong></p>
                        <p>The selected changes will be applied to all selected products.</p>
                        <ul id="bulkEditConfirmSummary" class="mb-0"></ul>
                        <div id="bulkEditConfirmError" class="alert alert-danger mt-3 mb-0" style="display: none;"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-outline-secondary" id="bulkEditBackBtn" style="display: none;">Back</button>
                    <button type="button" class="btn btn-primary" id="bulkEditReviewBtn">Continue</button>
                    <button type="button" class="btn btn-primary" id="bulkEditApplyBtn" style="display: none;">Apply Changes</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Merge Product Modal -->
    <div class="modal fade" id="mergeProductModal" tabindex="-1" role="dialog" aria-labelledby="mergeProductModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="mergeProductModalLabel">Merge/Replace Product</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <strong>Product to merge:</strong> <span id="mergeProductName"></span> (<span id="mergeProductPsmCode"></span>)
                        <br><small>This product will be merged into the correct product you select below. All references will be updated.</small>
                    </div>
                    <div class="form-group">
                        <label for="productSearch">Search for correct product:</label>
                        <input type="text" class="form-control" id="productSearch" placeholder="Type product name or PSM code...">
                        <input type="hidden" id="wrongProductId" value="">
                    </div>
                    <div id="productSearchResults" style="max-height: 300px; overflow-y: auto; margin-top: 10px;">
                        <!-- Search results will appear here -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="confirmMergeBtn" disabled>Confirm Merge</button>
                </div>
            </div>
        </div>
    </div>

    <div id="productsPageLoader" aria-live="polite" aria-busy="true">
        <div class="products-loader-card">
            <div class="spinner-border text-primary mb-3" role="status">
                <span class="sr-only">Loading...</span>
            </div>
            <h5 class="mb-2" id="productsLoaderTitle">Processing</h5>
            <p class="text-muted mb-0" id="productsLoaderMessage">Please wait...</p>
        </div>
    </div>
@stop

@section('css')
    @include('partials.responsive-css')
    <style>
        #productsTable th.product-select-cell,
        #productsTable td.product-select-cell {
            width: 42px;
            min-width: 42px;
            text-align: center;
            vertical-align: middle;
        }

        #productsTable td.product-select-cell {
            cursor: default;
        }

        #productsTable td.product-select-cell .row-checkbox,
        #productsTable th .row-checkbox,
        #selectAll {
            width: 16px;
            height: 16px;
            cursor: pointer;
            position: relative;
            z-index: 2;
            vertical-align: middle;
        }

        #productsTable td.product-select-cell:before,
        #productsTable td.product-select-cell:after {
            display: none !important;
        }

        #productsPageLoader {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 10050;
            background: rgba(0, 0, 0, 0.45);
            align-items: center;
            justify-content: center;
        }

        #productsPageLoader.is-active {
            display: flex;
        }

        #productsPageLoader .products-loader-card {
            background: #fff;
            border-radius: 8px;
            padding: 2rem 2.5rem;
            text-align: center;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
            max-width: 440px;
            width: 90%;
        }

        #productsPageLoader .products-loader-card .spinner-border {
            width: 3rem;
            height: 3rem;
        }

        #productCatalogFilters.product-list-filters {
            margin-bottom: 0.75rem;
            padding: 0.85rem 1rem 0.7rem;
            background: #f8f9fb;
            border: 1px solid #e4e7eb;
            border-radius: 0.35rem;
        }

        #productCatalogFilters .product-list-filters__grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1fr) auto;
            gap: 0.75rem 1rem;
            align-items: end;
        }

        #productCatalogFilters .product-list-filters__field {
            min-width: 0;
        }

        #productCatalogFilters .product-list-filters__field label {
            display: block;
            margin-bottom: 0.3rem;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.02em;
            text-transform: uppercase;
            color: #6c757d;
        }

        #productCatalogFilters .product-list-filters__actions .btn {
            height: calc(2.25rem + 2px);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
            white-space: nowrap;
            padding-left: 0.9rem;
            padding-right: 0.9rem;
        }

        #productCatalogFilters .product-list-filters__check {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            margin: 0.75rem 0 0;
            padding-top: 0.65rem;
            border-top: 1px solid #e4e7eb;
            width: 100%;
            font-weight: 400;
            color: #495057;
            cursor: pointer;
        }

        #productCatalogFilters .product-list-filters__check input {
            margin: 0;
            flex: 0 0 auto;
        }

        #productCatalogFilters .select2-container {
            width: 100% !important;
        }

        #productCatalogFilters .select2-container--default .select2-selection--single {
            height: calc(2.25rem + 2px);
            border: 1px solid #ced4da;
            border-radius: 0.25rem;
            background-color: #fff;
        }

        #productCatalogFilters .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: calc(2.25rem + 2px);
            padding-left: 0.75rem;
            padding-right: 2.5rem;
            color: #495057;
        }

        #productCatalogFilters .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: calc(2.25rem + 2px);
        }

        #productCatalogFilters .select2-container--default .select2-selection--single .select2-selection__clear {
            height: calc(2.25rem + 2px);
            line-height: calc(2.25rem + 2px);
            margin-right: 1.15rem;
        }

        #bulkEditModal .select2-container {
            width: 100% !important;
            max-width: 100%;
        }

        #bulkEditModal .select2-container--default .select2-selection--single {
            height: calc(2.25rem + 2px);
            border: 1px solid #ced4da;
            border-radius: 0.25rem;
            background-color: #fff;
        }

        #bulkEditModal .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: calc(2.25rem + 2px);
            padding-left: 0.75rem;
            padding-right: 1.5rem;
            color: #495057;
            text-overflow: ellipsis;
        }

        #bulkEditModal .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: calc(2.25rem + 2px);
        }

        #bulkEditModal .select2-container--default.select2-container--disabled .select2-selection--single {
            background-color: #e9ecef;
            cursor: not-allowed;
        }

        #bulkEditModal .select2-dropdown {
            z-index: 1060;
        }

        #bulkEditModal .select2-search--dropdown .select2-search__field {
            width: 100% !important;
        }

        @media (max-width: 991.98px) {
            #productCatalogFilters .product-list-filters__grid {
                grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            }

            #productCatalogFilters .product-list-filters__actions .btn {
                width: 100%;
            }
        }

        @media (max-width: 575.98px) {
            #productCatalogFilters.product-list-filters {
                padding: 0.75rem;
            }

            #productCatalogFilters .product-list-filters__grid {
                grid-template-columns: minmax(0, 1fr);
            }
        }

        .products-list-sticky {
            position: sticky;
            top: 0;
            z-index: 30;
            background: #fff;
            box-shadow: 0 1px 0 rgba(0, 0, 0, 0.08);
        }

        body.layout-navbar-fixed .products-list-sticky {
            top: 3.5rem;
        }

        .products-list-sticky > .card-header {
            border-top-left-radius: 0.25rem;
            border-top-right-radius: 0.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .products-list-sticky > .card-header .card-tools {
            float: none;
            margin-left: auto;
            display: flex;
            flex-wrap: wrap;
            gap: 0.35rem;
        }

        .products-list-sticky > .card-header .card-tools .btn {
            margin-right: 0 !important;
        }

        .products-list-sticky__panel {
            padding: 0.75rem 1.25rem 0.75rem;
            background: #fff;
        }

        .products-list-sticky #productCatalogFilters.product-list-filters {
            margin-bottom: 0;
        }

        .products-list-sticky #selectedProductsSummary {
            margin: 0.75rem 0 0;
        }

        .products-list-table {
            padding-top: 0.75rem;
        }

        .products-all-browser {
            margin-bottom: 0.75rem;
            padding: 0.75rem 1rem;
            background: #f8f9fa;
            border: 1px solid #e3e6ea;
            border-radius: 0.25rem;
        }

        .products-all-browser__controls {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.4rem;
        }

        .products-list-table.is-view-all .dataTables_paginate {
            display: none;
        }

        #productsAllSentinel {
            height: 1px;
        }
    </style>
@stop

@section('js')
    @include('partials.responsive-js')
    <script>
        $(document).ready(function() {
            var maxSyncEnrich = {{ (int) config('inventory_ai.max_sync_product_enrich', 100) }};
            var bulkEditCategories = @json($bulkEditCategories);
            var bulkEditSubCategories = @json($bulkEditSubCategories);
            var bulkEditBrands = @json($bulkEditBrands);
            var pageLoaderActive = false;
            var enrichRequestActive = false;

            function showProductsLoader(title, message) {
                pageLoaderActive = true;
                $('#productsLoaderTitle').text(title);
                $('#productsLoaderMessage').text(message);
                $('#productsPageLoader').addClass('is-active');
                $('#bulkEditBtn, #bulkVerifyBtn, #bulkEnrichBtn, #bulkDeleteBtn, #selectAll, #filterUnverified, #filterBrand, #filterCategory, #filterSubCategory, #clearProductFilters').prop('disabled', true);
                $('#productsTable .row-checkbox, #productsTable .enrich-product-btn').prop('disabled', true);
                $('a[href="{{ route('admin.products.create') }}"]').addClass('disabled').attr('aria-disabled', 'true');
            }

            function hideProductsLoader() {
                pageLoaderActive = false;
                $('#productsPageLoader').removeClass('is-active');
                $('#bulkEditBtn, #bulkVerifyBtn, #bulkEnrichBtn, #bulkDeleteBtn, #selectAll, #filterUnverified, #filterBrand, #filterCategory, #filterSubCategory, #clearProductFilters').prop('disabled', false);
                $('#productsTable .row-checkbox, #productsTable .enrich-product-btn').prop('disabled', false);
                $('a[href="{{ route('admin.products.create') }}"]').removeClass('disabled').removeAttr('aria-disabled');
            }

            // Restore filter states from localStorage BEFORE initializing DataTables
            var savedUnverified = localStorage.getItem('products_filter_unverified');
            if (savedUnverified === '1') {
                $('#filterUnverified').prop('checked', true);
            }

            var savedBrand = localStorage.getItem('products_filter_brand') || '';
            var savedCategory = localStorage.getItem('products_filter_category') || '';
            var savedSubCategory = localStorage.getItem('products_filter_subcategory') || '';
            var applyingProductFilters = false;
            var productFiltersTouched = false;
            var unsetCatalogValue = 'none';

            function storeProductFilter(key, value) {
                if (value) {
                    localStorage.setItem(key, value);
                } else {
                    localStorage.removeItem(key);
                }
            }

            function persistProductCatalogFilters() {
                storeProductFilter('products_filter_brand', $('#filterBrand').val() || '');
                storeProductFilter('products_filter_category', $('#filterCategory').val() || '');
                storeProductFilter('products_filter_subcategory', $('#filterSubCategory').val() || '');
            }

            function populateCatalogFilterOptions($select, items, selectedId) {
                var selected = selectedId === undefined || selectedId === null ? '' : String(selectedId);
                $select.empty();
                $select.append($('<option>', { value: '', text: '' }));
                $.each(items, function(_, item) {
                    $select.append($('<option>', { value: item.id, text: item.name }));
                });
                $select.append($('<option>', { value: unsetCatalogValue, text: 'Not Set (-)' }));
                if (selected !== '' && $select.find('option[value="' + selected + '"]').length) {
                    $select.val(selected);
                } else {
                    $select.val('');
                }
                if ($select.data('select2')) {
                    $select.trigger('change.select2');
                }
            }

            function subCategoryFilterItems(categoryId) {
                var items = [];
                $.each(bulkEditSubCategories, function(_, subCategory) {
                    if (categoryId && categoryId !== unsetCatalogValue && String(subCategory.category_id) !== String(categoryId)) {
                        return;
                    }
                    var label = subCategory.name;
                    if ((!categoryId || categoryId === unsetCatalogValue) && subCategory.category_name) {
                        label += ' (' + subCategory.category_name + ')';
                    }
                    items.push({ id: subCategory.id, name: label });
                });
                return items;
            }

            function populateSubCategoryFilter(selectedId) {
                populateCatalogFilterOptions(
                    $('#filterSubCategory'),
                    subCategoryFilterItems($('#filterCategory').val() || ''),
                    selectedId
                );
            }

            function initCatalogFilterSelect($select, placeholder) {
                if (!$.fn.select2) {
                    return;
                }
                $select.select2({
                    placeholder: placeholder,
                    allowClear: true,
                    width: '100%'
                });
            }

            populateCatalogFilterOptions($('#filterBrand'), bulkEditBrands, savedBrand);
            populateCatalogFilterOptions($('#filterCategory'), bulkEditCategories, savedCategory);
            populateSubCategoryFilter(savedSubCategory);
            persistProductCatalogFilters();
            initCatalogFilterSelect($('#filterBrand'), 'All Brands');
            initCatalogFilterSelect($('#filterCategory'), 'All Categories');
            initCatalogFilterSelect($('#filterSubCategory'), 'All Sub Categories');

            var PRODUCT_LIST_BATCH = {{ (int) \App\Http\Controllers\Admin\ProductController::PRODUCT_LIST_MAX_PAGE_SIZE }};
            var productsViewAll = false;
            var productsViewAllStart = 0;
            var productsViewAllTotal = 0;
            var productsViewAllLoading = false;
            var productsViewAllClamping = false;
            var productsAllUserScrolled = false;
            var productsSelected = {};
            $('#productsAllPrev').text('Previous ' + PRODUCT_LIST_BATCH);
            $('#productsAllNext').text('Next ' + PRODUCT_LIST_BATCH);
            $('#productsAllJump').attr('step', PRODUCT_LIST_BATCH);

            function rememberProductCheckbox(checkbox) {
                var $box = $(checkbox);
                var id = String($box.val());
                if ($box.is(':checked')) {
                    productsSelected[id] = {
                        name: $box.data('name') || '',
                        verified: String($box.data('verified'))
                    };
                } else {
                    delete productsSelected[id];
                }
            }

            function currentBulkSelection() {
                if (productsViewAll) {
                    var storedIds = [];
                    var storedNames = [];
                    var storedHasUnverified = false;
                    Object.keys(productsSelected).forEach(function(id) {
                        storedIds.push(id);
                        storedNames.push(productsSelected[id].name || '');
                        if (String(productsSelected[id].verified) === '0') {
                            storedHasUnverified = true;
                        }
                    });
                    return { ids: storedIds, names: storedNames, hasUnverified: storedHasUnverified };
                }

                var ids = [];
                var names = [];
                var hasUnverified = false;
                $('#productsTable .row-checkbox:checked').each(function() {
                    ids.push($(this).val());
                    names.push($(this).data('name'));
                    if (String($(this).data('verified')) === '0') {
                        hasUnverified = true;
                    }
                });
                return { ids: ids, names: names, hasUnverified: hasUnverified };
            }

            function clearProductSelection() {
                productsSelected = {};
                $('#selectAll, #productsTable .row-checkbox').prop('checked', false);
            }

            function updateProductsAllBrowser(settings) {
                var $list = $('#productsTable').closest('.products-list-table');
            if (!productsViewAll) {
                $('#productsAllBrowser, #productsAllSentinel').hide();
                $('#productsAllStatus').text('');
                $list.removeClass('is-view-all');
                $('#selectAll').attr('title', 'Select all rows on this page');
                return;
            }

                $list.addClass('is-view-all');

                var json = (settings && settings.json) ? settings.json : {};
                var total = typeof json.recordsFiltered === 'number' ? json.recordsFiltered : 0;
                productsViewAllTotal = total;
                var shown = json.data ? json.data.length : 0;

                if (total > 0 && productsViewAllStart >= total && !productsViewAllClamping) {
                    productsViewAllClamping = true;
                    productsViewAllStart = Math.floor((total - 1) / PRODUCT_LIST_BATCH) * PRODUCT_LIST_BATCH;
                    productsTable.ajax.reload(function() {
                        productsViewAllClamping = false;
                    }, false);
                    return;
                }

                if (total <= PRODUCT_LIST_BATCH) {
                    $('#productsAllBrowser, #productsAllSentinel').hide();
                    $('#productsAllStatus').text('');
                    $('#selectAll').attr('title', 'Select all rows');
                    return;
                }

                $('#productsAllBrowser, #productsAllSentinel').show();
                $('#selectAll').attr('title', 'Select all rows in this batch');

                var from = total === 0 || shown === 0 ? 0 : productsViewAllStart + 1;
                var to = shown === 0 ? 0 : productsViewAllStart + shown;
                var batchCount = total === 0 ? 0 : Math.ceil(total / PRODUCT_LIST_BATCH);
                var batchNumber = total === 0 ? 0 : Math.floor(productsViewAllStart / PRODUCT_LIST_BATCH) + 1;
                $('#productsAllStatus').text(
                    total === 0
                        ? 'No products match the current search and filters.'
                        : 'Showing products ' + from + '–' + to + ' of ' + total + ' (batch ' + batchNumber + ' of ' + batchCount + ').'
                );
                $('#productsAllJump').attr('max', Math.max(total, 1));
                $('#productsAllFirst, #productsAllPrev').prop('disabled', productsViewAllStart <= 0);
                $('#productsAllNext, #productsAllLast').prop('disabled', total === 0 || productsViewAllStart + PRODUCT_LIST_BATCH >= total);
                $('#productsAllGo').prop('disabled', false);
            }

            function moveProductsAllWindow(nextStart) {
                if (!productsViewAll || productsViewAllLoading) {
                    return;
                }
                if (nextStart < 0) {
                    nextStart = 0;
                }
                if (productsViewAllTotal > 0 && nextStart >= productsViewAllTotal) {
                    return;
                }
                if (nextStart === productsViewAllStart) {
                    return;
                }
                productsViewAllStart = nextStart;
                productsAllUserScrolled = false;
                productsViewAllLoading = true;
                $('#productsAllFirst, #productsAllPrev, #productsAllNext, #productsAllLast, #productsAllGo').prop('disabled', true);
                var allBrowser = document.getElementById('productsAllBrowser');
                if (allBrowser && allBrowser.scrollIntoView) {
                    allBrowser.scrollIntoView({ block: 'start' });
                }
                productsTable.ajax.reload(null, false);
            }

            var productsTable = initResponsiveDataTable('productsTable', {
                skipLargeAllMode: true,
                "processing": true,
                "serverSide": true,
                "stateSave": false, // Disable state saving to show all records by default
                "stateDuration": -1, // Don't save state
                "responsive": {
                    "details": {
                        "type": "column",
                        "target": 1
                    }
                },
                "ajax": {
                    "url": "{{ route('admin.products.data') }}",
                    "type": "GET",
                    "data": function(d) {
                        d.unverified_only = $('#filterUnverified').is(':checked') ? '1' : '0';
                        var brandId = $('#filterBrand').val();
                        var categoryId = $('#filterCategory').val();
                        var subCategoryId = $('#filterSubCategory').val();
                        if (brandId) {
                            d.brand_id = brandId;
                        }
                        if (categoryId) {
                            d.category_id = categoryId;
                        }
                        if (subCategoryId) {
                            d.sub_category_id = subCategoryId;
                        }
                    },
                    "error": function(xhr, error, thrown) {
                        productsViewAllLoading = false;
                        if (error === 'abort') {
                            return;
                        }
                        console.error('DataTables AJAX error:', error, thrown);
                        var message = 'Error loading products data. Please try again.';
                        if (xhr && xhr.responseJSON && xhr.responseJSON.error) {
                            message = xhr.responseJSON.error;
                        }
                        $('#productsListErrorMessage').text(message);
                        $('#productsListError').show();
                        $('#productsAllFirst, #productsAllPrev, #productsAllNext, #productsAllLast, #productsAllGo').prop('disabled', false);
                    }
                },
                "columns": [
                    {
                        "data": "checkbox",
                        "name": "checkbox",
                        "orderable": false,
                        "searchable": false,
                        "render": function(data, type, row) {
                            var model = $('<div/>').text(row.model || '').html();
                            return '<input type="checkbox" class="row-checkbox" name="product_ids[]" value="' + row.id + '" data-name="' + model + '" data-verified="' + (row.is_verified == 1 ? '1' : '0') + '">';
                        }
                    },
                    { "data": "id", "name": "id" },
                    {
                        "data": "brand",
                        "name": "brand",
                        "render": function(data, type, row) {
                            return '<span class="badge badge-success">' + data + '</span>';
                        }
                    },
                    {
                        "data": "model",
                        "name": "model",
                        "render": function(data, type, row) {
                            return '<strong>' + data + '</strong>';
                        }
                    },
                    {
                        "data": "category",
                        "name": "category",
                        "render": function(data, type, row) {
                            return '<span class="badge badge-primary">' + data + '</span>';
                        }
                    },
                    {
                        "data": "sub_category",
                        "name": "sub_category",
                        "render": function(data, type, row) {
                            if (data === '—') {
                                return '<span class="text-muted">—</span>';
                            }
                            return '<span class="badge badge-info">' + data + '</span>';
                        }
                    },
                    { "data": "psm_code", "name": "psm_code" },
                    {
                        "data": "replacement_price",
                        "name": "replacement_price"
                    },
                    {
                        "data": "dimensions",
                        "name": "dimensions",
                        "render": function(data, type, row) {
                            var parts = [];
                            if (row.dimensions) parts.push('<div><small class="text-muted">Dimensions:</small> ' + row.dimensions + '</div>');
                            if (row.weight) parts.push('<div><small class="text-muted">Weight:</small> ' + row.weight + '</div>');
                            if (parts.length === 0) return '<span class="text-muted">—</span>';
                            return parts.join('');
                        }
                    },
                    {
                        "data": "is_verified",
                        "name": "is_verified",
                        "render": function(data, type, row) {
                            if (data == 1) {
                                return '<span class="badge badge-success" title="Verified"><i class="fas fa-check-circle"></i> Verified</span>';
                            } else {
                                return '<span class="badge badge-warning" title="Unverified"><i class="fas fa-times-circle"></i> Unverified</span>';
                            }
                        }
                    },
                    { "data": "created_at", "name": "created_at" },
                    {
                        "data": "actions",
                        "name": "actions",
                        "orderable": false,
                        "searchable": false
                    }
                ],
                "columnDefs": [
                    { "className": "product-select-cell text-center", "targets": 0 },
                    { "orderable": false, "targets": [0, 8, 11] },
                    { "searchable": false, "targets": [0, 8, 11] },
                    { "responsivePriority": 1, "targets": 0 },
                    { "responsivePriority": 2, "targets": 3 },
                    { "responsivePriority": 3, "targets": 11 },
                    { "responsivePriority": 4, "targets": [3, 4] }
                ],
                "order": [[1, "desc"]], // Sort by ID descending by default
                "pageLength": 25,
                "lengthMenu": [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                "stateSave": false, // Disable state saving to prevent search filter persistence
                "stateDuration": -1, // Don't save state
                "infoCallback": function(settings, start, end, max, total, pre) {
                    if (!productsViewAll) {
                        return pre;
                    }
                    if (!total) {
                        return 'Showing 0 products';
                    }
                    var shown = settings.json && settings.json.data ? settings.json.data.length : 0;
                    var from = shown === 0 ? 0 : productsViewAllStart + 1;
                    var to = shown === 0 ? 0 : productsViewAllStart + shown;
                    return 'Showing ' + from + ' to ' + to + ' of ' + total + ' products';
                },
                "drawCallback": function(settings) {
                    productsViewAllLoading = false;
                    if (settings.json && !settings.json.error) {
                        $('#productsListError').hide();
                    }
                    if (productsViewAll) {
                        $('#productsTable .row-checkbox').each(function() {
                            var id = String($(this).val());
                            $(this).prop('checked', Object.prototype.hasOwnProperty.call(productsSelected, id));
                        });
                    }
                    var totalCheckboxes = $('#productsTable .row-checkbox').length;
                    var checkedCheckboxes = $('#productsTable .row-checkbox:checked').length;
                    $('#selectAll').prop('checked', totalCheckboxes > 0 && totalCheckboxes === checkedCheckboxes);
                    updateProductsAllBrowser(settings);
                    updateBulkButtons();
                }
            });

            productsTable.on('preXhr.dt', function(e, settings, data) {
                if (Number(data.length) === -1) {
                    productsViewAll = true;
                    data.length = PRODUCT_LIST_BATCH;
                    data.start = productsViewAllStart;
                }
            });

            $('#productsListRetry').on('click', function() {
                $('#productsListError').hide();
                productsTable.ajax.reload(null, false);
            });

            $('#productsAllFirst').on('click', function() {
                moveProductsAllWindow(0);
            });
            $('#productsAllPrev').on('click', function() {
                moveProductsAllWindow(productsViewAllStart - PRODUCT_LIST_BATCH);
            });
            $('#productsAllNext').on('click', function() {
                moveProductsAllWindow(productsViewAllStart + PRODUCT_LIST_BATCH);
            });
            $('#productsAllLast').on('click', function() {
                if (productsViewAllTotal < 1) {
                    return;
                }
                moveProductsAllWindow(Math.floor((productsViewAllTotal - 1) / PRODUCT_LIST_BATCH) * PRODUCT_LIST_BATCH);
            });
            function jumpProductsAllWindow() {
                var raw = parseInt($('#productsAllJump').val(), 10);
                if (!raw || raw < 1) {
                    raw = 1;
                }
                if (productsViewAllTotal > 0 && raw > productsViewAllTotal) {
                    raw = productsViewAllTotal;
                }
                moveProductsAllWindow(Math.floor((raw - 1) / PRODUCT_LIST_BATCH) * PRODUCT_LIST_BATCH);
            }
            $('#productsAllGo').on('click', jumpProductsAllWindow);
            $('#productsAllJump').on('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    jumpProductsAllWindow();
                }
            });

            if (window.IntersectionObserver) {
                var productsAllLastScroll = window.scrollY || 0;
                $(window).on('scroll.productsAll', function() {
                    var currentScroll = window.scrollY || 0;
                    if (currentScroll > productsAllLastScroll + 8) {
                        productsAllUserScrolled = true;
                    }
                    productsAllLastScroll = currentScroll;
                });
                var productsAllSentinel = document.getElementById('productsAllSentinel');
                if (productsAllSentinel) {
                    var productsAllObserver = new IntersectionObserver(function(entries) {
                        if (!productsViewAll || productsViewAllLoading || !productsAllUserScrolled) {
                            return;
                        }
                        if (!entries.some(function(entry) { return entry.isIntersecting; })) {
                            return;
                        }
                        if (productsViewAllTotal < 1 || productsViewAllStart + PRODUCT_LIST_BATCH >= productsViewAllTotal) {
                            return;
                        }
                        productsAllUserScrolled = false;
                        moveProductsAllWindow(productsViewAllStart + PRODUCT_LIST_BATCH);
                    }, { threshold: 0.6 });
                    productsAllObserver.observe(productsAllSentinel);
                }
            }

            // Clear any saved state from localStorage for this table (DataTables default)
            localStorage.removeItem('DataTables_productsTable');

            // Restore all saved filter states from localStorage after table is initialized
            setTimeout(function() {
                var savedSearch = localStorage.getItem('products_filter_search');
                var savedPageLength = localStorage.getItem('products_filter_pageLength');
                var savedOrder = localStorage.getItem('products_filter_order');
                var savedPage = localStorage.getItem('products_filter_page');

                var needsRedraw = false;

                // Restore page length (doesn't trigger draw automatically)
                if (savedPageLength) {
                    var pageLen = parseInt(savedPageLength);
                    if (productsTable.page.len() !== pageLen) {
                        productsTable.page.len(pageLen);
                        needsRedraw = true;
                    }
                }

                // Restore sorting (doesn't trigger draw automatically for server-side)
                if (savedOrder) {
                    try {
                        var orderArray = JSON.parse(savedOrder);
                        if (Array.isArray(orderArray) && orderArray.length > 0) {
                            productsTable.order(orderArray);
                            needsRedraw = true;
                        }
                    } catch (e) {
                        console.error('Error parsing saved order:', e);
                    }
                }

                // Restore search filter (doesn't trigger draw automatically for server-side)
                if (savedSearch && productsTable.search() !== savedSearch) {
                    productsTable.search(savedSearch);
                    needsRedraw = true;
                }

                // Restore pagination and trigger single draw with all restored state.
                // Skip the saved page when a catalog filter already reset paging.
                if (needsRedraw || (savedPage && !productFiltersTouched)) {
                    if (savedPage && !productFiltersTouched) {
                        productsTable.page(parseInt(savedPage));
                    }
                    productsTable.draw(false); // false = don't reset paging
                }
            }, 200);

            // Filter unverified products - save state to localStorage
            $('#filterUnverified').on('change', function() {
                var isChecked = $(this).is(':checked') ? '1' : '0';
                localStorage.setItem('products_filter_unverified', isChecked);
                // Reset to first page when filter changes
                localStorage.setItem('products_filter_page', '0');
                productsTable.ajax.reload();
            });

            function reloadProductListForFilters() {
                productFiltersTouched = true;
                persistProductCatalogFilters();
                localStorage.setItem('products_filter_page', '0');
                if (productsViewAll) {
                    productsViewAllStart = 0;
                    productsSelected = {};
                }
                productsTable.ajax.reload();
            }

            $('#filterBrand').on('change', function() {
                if (applyingProductFilters) {
                    return;
                }
                reloadProductListForFilters();
            });

            $('#filterCategory').on('change', function() {
                if (applyingProductFilters) {
                    return;
                }
                applyingProductFilters = true;
                populateSubCategoryFilter($('#filterSubCategory').val());
                applyingProductFilters = false;
                reloadProductListForFilters();
            });

            $('#filterSubCategory').on('change', function() {
                if (applyingProductFilters) {
                    return;
                }
                reloadProductListForFilters();
            });

            $('#clearProductFilters').on('click', function() {
                applyingProductFilters = true;
                $('#filterBrand').val('').trigger('change');
                $('#filterCategory').val('').trigger('change');
                populateSubCategoryFilter('');
                applyingProductFilters = false;
                reloadProductListForFilters();
            });

            // DataTables also emits search on ordinary reloads. Reset All only when the text changes.
            var lastProductSearch = productsTable.search();
            productsTable.on('search.dt', function() {
                var searchValue = productsTable.search();
                if (searchValue) {
                    localStorage.setItem('products_filter_search', searchValue);
                } else {
                    localStorage.removeItem('products_filter_search');
                }
                // Reset to first page when search changes
                localStorage.setItem('products_filter_page', '0');
                if (searchValue !== lastProductSearch) {
                    lastProductSearch = searchValue;
                    if (productsViewAll) {
                        productsViewAllStart = 0;
                        productsSelected = {};
                    }
                }
            });

            // Save pagination state to localStorage
            productsTable.on('page.dt', function() {
                localStorage.setItem('products_filter_page', productsTable.page());
            });

            productsTable.on('length.dt', function(e, settings, len) {
                var nextAll = parseInt(len, 10) === -1;
                if (nextAll !== productsViewAll) {
                    clearProductSelection();
                }
                productsViewAll = nextAll;
                productsViewAllStart = 0;
                localStorage.setItem('products_filter_pageLength', len);
                localStorage.setItem('products_filter_page', '0'); // Reset to first page when page length changes
            });

            // DataTables also emits order on ordinary reloads. Reset All only when the sort changes.
            var lastProductOrder = JSON.stringify(productsTable.order());
            productsTable.on('order.dt', function() {
                var order = productsTable.order();
                var serializedOrder = JSON.stringify(order);
                localStorage.setItem('products_filter_order', serializedOrder);
                if (serializedOrder !== lastProductOrder) {
                    lastProductOrder = serializedOrder;
                    if (productsViewAll) {
                        productsViewAllStart = 0;
                    }
                }
            });

            // Bulk actions for server-side DataTable
            $('#selectAll').on('change', function() {
                var checked = $(this).is(':checked');
                $('#productsTable .row-checkbox').each(function() {
                    $(this).prop('checked', checked);
                    if (productsViewAll) {
                        rememberProductCheckbox(this);
                    }
                });
                updateBulkButtons();
            });

            $(document).on('change', '.row-checkbox', function(e) {
                e.stopPropagation();
                if (productsViewAll) {
                    rememberProductCheckbox(this);
                }
                updateBulkButtons();
                var totalCheckboxes = $('#productsTable .row-checkbox').length;
                var checkedCheckboxes = $('#productsTable .row-checkbox:checked').length;
                $('#selectAll').prop('checked', totalCheckboxes > 0 && totalCheckboxes === checkedCheckboxes);
            });

            $(document).on('click', '.row-checkbox', function(e) {
                e.stopPropagation();
            });

            function updateBulkButtons() {
                if (pageLoaderActive) {
                    return;
                }

                var selected = currentBulkSelection();
                var checkedCount = selected.ids.length;

                if (checkedCount > 0) {
                    var selectedLabel = checkedCount === 1 ? '1 product selected' : checkedCount + ' products selected';
                    $('#selectedProductsSummaryText').text(selectedLabel);
                    $('#selectedProductsSummary').show();
                    $('#bulkEditBtn').show().html('<i class="fas fa-edit"></i> <span class="d-none d-lg-inline">Multi-Edit (' + checkedCount + ')</span><span class="d-lg-none">Edit</span>');
                    $('#bulkDeleteBtn').show().html('<i class="fas fa-trash"></i> <span class="d-none d-lg-inline">Delete Selected (' + checkedCount + ')</span><span class="d-lg-none">Delete</span>');
                    $('#bulkEnrichBtn').show().html('<i class="fas fa-robot"></i> <span class="d-none d-lg-inline">AI Enrich Selected (' + checkedCount + ')</span><span class="d-lg-none">AI Enrich</span>');

                    var hasUnverified = selected.hasUnverified;

                    // Only show verify button if there are unverified products selected
                    if (hasUnverified) {
                        $('#bulkVerifyBtn').show().html('<i class="fas fa-check-circle"></i> <span class="d-none d-lg-inline">Verify Selected (' + checkedCount + ')</span><span class="d-lg-none">Verify</span>');
                    } else {
                        $('#bulkVerifyBtn').hide();
                    }
                } else {
                    $('#selectedProductsSummary').hide();
                    $('#bulkEditBtn').hide();
                    $('#bulkDeleteBtn').hide();
                    $('#bulkVerifyBtn').hide();
                    $('#bulkEnrichBtn').hide();
                }
            }

            $('#bulkEnrichBtn').on('click', function() {
                var selectedIds = currentBulkSelection().ids;

                if (selectedIds.length === 0) {
                    alert('Please select at least one product to enrich.');
                    return;
                }

                if (selectedIds.length > maxSyncEnrich) {
                    alert('You can enrich at most ' + maxSyncEnrich + ' products at once.');
                    return;
                }

                var message = 'Run synchronous AI specification enrichment for ' + selectedIds.length + ' selected product(s)?\n\n'
                    + 'This runs immediately in your browser session (not queued) and may take several minutes depending on API rate limits.\n\n'
                    + 'Do not close this page until processing completes.';

                if (!confirm(message)) {
                    return;
                }

                var $btn = $(this);
                showProductsLoader(
                    'Running AI Enrichment',
                    'Processing ' + selectedIds.length + ' product(s) synchronously. Please do not close this page.'
                );
                $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Enriching...');

                $.ajax({
                    url: '{{ route("admin.products.bulk-enrich-specifications") }}',
                    method: 'POST',
                    timeout: 0,
                    data: {
                        _token: '{{ csrf_token() }}',
                        confirm_enrich: 1,
                        product_ids: selectedIds
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#enrichResultsSummary').text(response.message);
                            var rowsHtml = '';
                            (response.results || []).forEach(function(row) {
                                var badgeClass = 'secondary';
                                if (row.outcome === 'Success') badgeClass = 'success';
                                else if (row.outcome === 'Skipped') badgeClass = 'secondary';
                                else if (row.outcome === 'Still Rejected' || row.outcome === 'Failed') badgeClass = 'warning';
                                else badgeClass = 'danger';

                                rowsHtml += '<tr>';
                                rowsHtml += '<td>' + row.product_id + '</td>';
                                rowsHtml += '<td>' + $('<div/>').text(row.product_name).html() + '</td>';
                                rowsHtml += '<td><span class="badge badge-' + badgeClass + '">' + row.outcome + '</span></td>';
                                rowsHtml += '<td>' + $('<div/>').text(row.status).html() + '</td>';
                                rowsHtml += '<td>' + $('<div/>').text(row.message).html() + '</td>';
                                rowsHtml += '</tr>';
                            });
                            $('#enrichResultsBody').html(rowsHtml);
                            $('#enrichResultsModal').modal('show');

                            clearProductSelection();
                            productsTable.ajax.reload(function() {
                                $('#selectAll').prop('checked', false);
                                updateBulkButtons();
                            });
                        } else {
                            alert('Error: ' + (response.message || 'Failed to enrich products.'));
                        }
                    },
                    error: function(xhr) {
                        var message = 'An error occurred while running AI enrichment.';
                        if (xhr.responseJSON && xhr.responseJSON.message) {
                            message = xhr.responseJSON.message;
                        }
                        alert(message);
                    },
                    complete: function() {
                        hideProductsLoader();
                        $btn.prop('disabled', false);
                        updateBulkButtons();
                    }
                });
            });

            function renderDimensionsCell(row) {
                var parts = [];
                if (row.dimensions) {
                    parts.push('<div><small class="text-muted">Dimensions:</small> ' + $('<div/>').text(row.dimensions).html() + '</div>');
                }
                if (row.weight) {
                    parts.push('<div><small class="text-muted">Weight:</small> ' + $('<div/>').text(row.weight).html() + '</div>');
                }
                if (parts.length === 0) {
                    return '<span class="text-muted">—</span>';
                }
                return parts.join('');
            }

            function updateProductSpecCell(product) {
                if (!product || product.id == null) {
                    return;
                }

                productsTable.rows().every(function() {
                    var data = this.data();
                    if (!data || String(data.id) !== String(product.id)) {
                        return;
                    }

                    data.dimensions = product.dimensions || null;
                    data.weight = product.weight || null;

                    var cellNode = productsTable.cell(this.index(), 8).node();
                    if (cellNode) {
                        $(cellNode).html(renderDimensionsCell(data));
                    }

                    var $child = $(this.node()).next('tr.child');
                    $child.find('li[data-dt-column="8"] .dtr-data').html(renderDimensionsCell(data));
                });
            }

            $(document).on('click', '.enrich-product-btn', function() {
                if (pageLoaderActive || enrichRequestActive) {
                    return;
                }

                var productId = $(this).data('product-id');
                var productName = $(this).data('product-name') || ('Product ' + productId);

                if (!productId) {
                    alert('Unable to identify the selected product.');
                    return;
                }

                var message = 'Run synchronous AI specification enrichment for "' + productName + '"?\n\n'
                    + 'This uses the same enrichment process as AI Enrich Selected and runs immediately in this browser session.\n\n'
                    + 'Existing dimensions and weight are included so incorrect values can be re-evaluated.\n\n'
                    + 'Do not close this page until processing completes.';

                if (!confirm(message)) {
                    return;
                }

                enrichRequestActive = true;
                var $btn = $(this);
                showProductsLoader(
                    'Running AI Enrichment',
                    'Processing "' + productName + '" synchronously. Please do not close this page.'
                );
                $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');

                $.ajax({
                    url: '{{ route("admin.products.enrich-specifications", ["product" => 0]) }}'.replace(/\/0\/enrich-specifications$/, '/' + productId + '/enrich-specifications'),
                    method: 'POST',
                    timeout: 0,
                    data: {
                        _token: '{{ csrf_token() }}',
                        confirm_enrich: 1
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#enrichResultsSummary').text(response.message);
                            var rowsHtml = '';
                            (response.results || []).forEach(function(row) {
                                var badgeClass = 'secondary';
                                if (row.outcome === 'Success') badgeClass = 'success';
                                else if (row.outcome === 'Skipped') badgeClass = 'secondary';
                                else if (row.outcome === 'Still Rejected' || row.outcome === 'Failed') badgeClass = 'warning';
                                else badgeClass = 'danger';

                                rowsHtml += '<tr>';
                                rowsHtml += '<td>' + row.product_id + '</td>';
                                rowsHtml += '<td>' + $('<div/>').text(row.product_name).html() + '</td>';
                                rowsHtml += '<td><span class="badge badge-' + badgeClass + '">' + row.outcome + '</span></td>';
                                rowsHtml += '<td>' + $('<div/>').text(row.status).html() + '</td>';
                                rowsHtml += '<td>' + $('<div/>').text(row.message).html() + '</td>';
                                rowsHtml += '</tr>';
                            });
                            $('#enrichResultsBody').html(rowsHtml);
                            $('#enrichResultsModal').modal('show');
                            updateProductSpecCell(response.product);
                        } else {
                            alert('Error: ' + (response.message || 'Failed to enrich product.'));
                        }
                    },
                    error: function(xhr) {
                        var message = 'An error occurred while running AI enrichment.';
                        if (xhr.responseJSON && xhr.responseJSON.message) {
                            message = xhr.responseJSON.message;
                        }
                        alert(message);
                    },
                    complete: function() {
                        enrichRequestActive = false;
                        $btn.prop('disabled', false).html('<i class="fas fa-robot"></i>');
                        hideProductsLoader();
                        updateBulkButtons();
                    }
                });
            });

            $('#bulkDeleteBtn').on('click', function() {
                var selected = currentBulkSelection();
                var selectedIds = selected.ids;
                var selectedNames = selected.names;

                if (selectedIds.length === 0) {
                    alert('Please select at least one product to delete.');
                    return;
                }

                var message = 'Are you sure you want to delete ' + selectedIds.length + ' product/products?\n\n';
                message += 'Products to be deleted:\n';
                selectedNames.forEach(function(name, index) {
                    message += (index + 1) + '. ' + name + '\n';
                });
                message += '\nThis action cannot be undone!';

                if (confirm(message)) {
                    var $btn = $(this);
                    showProductsLoader(
                        'Deleting Products',
                        'Deleting ' + selectedIds.length + ' selected product(s). Please wait...'
                    );
                    $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Deleting...');

                    $.ajax({
                        url: '{{ route("admin.products.bulk-delete") }}',
                        method: 'POST',
                        data: {
                            _token: '{{ csrf_token() }}',
                            product_ids: selectedIds
                        },
                        success: function(response) {
                            if (response.success) {
                                alert('Successfully deleted ' + response.deleted_count + ' product/products.');
                                // Refresh DataTable
                                clearProductSelection();
                                productsTable.ajax.reload(function() {
                                    // Reset select all checkbox and hide buttons after reload
                                    $('#selectAll').prop('checked', false);
                                    updateBulkButtons();
                                });
                            } else {
                                alert('Error: ' + (response.message || 'Failed to delete products.'));
                            }
                        },
                        error: function(xhr) {
                            var message = 'An error occurred while deleting products.';
                            if (xhr.responseJSON && xhr.responseJSON.message) {
                                message = xhr.responseJSON.message;
                            }
                            alert(message);
                        },
                        complete: function() {
                            hideProductsLoader();
                            $btn.prop('disabled', false);
                            updateBulkButtons();
                        }
                    });
                }
            });

            // Bulk verify functionality
            $('#bulkVerifyBtn').on('click', function() {
                var selectedIds = currentBulkSelection().ids;

                if (selectedIds.length === 0) {
                    alert('Please select at least one product to verify.');
                    return;
                }

                var message = 'Are you sure you want to verify ' + selectedIds.length + ' product/products?';

                if (confirm(message)) {
                    var $btn = $(this);
                    showProductsLoader(
                        'Verifying Products',
                        'Verifying ' + selectedIds.length + ' selected product(s). Please wait...'
                    );
                    $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Verifying...');

                    $.ajax({
                        url: '{{ route("admin.products.bulk-verify") }}',
                        method: 'POST',
                        data: {
                            _token: '{{ csrf_token() }}',
                            product_ids: selectedIds
                        },
                        success: function(response) {
                            if (response.success) {
                                alert('Successfully verified ' + response.updated_count + ' product/products.');
                                // Refresh DataTable
                                clearProductSelection();
                                productsTable.ajax.reload(function() {
                                    // Reset select all checkbox and hide buttons after reload
                                    $('#selectAll').prop('checked', false);
                                    updateBulkButtons();
                                });
                            } else {
                                alert('Error: ' + (response.message || 'Failed to verify products.'));
                            }
                        },
                        error: function(xhr) {
                            var message = 'An error occurred while verifying products.';
                            if (xhr.responseJSON && xhr.responseJSON.message) {
                                message = xhr.responseJSON.message;
                            }
                            alert(message);
                        },
                        complete: function() {
                            hideProductsLoader();
                            $btn.prop('disabled', false);
                            updateBulkButtons();
                        }
                    });
                }
            });

            var syncingBulkEditSelect = false;

            function syncBulkEditSelect($select) {
                if (!$select.data('select2')) {
                    return;
                }
                syncingBulkEditSelect = true;
                $select.trigger('change.select2');
                syncingBulkEditSelect = false;
            }

            function setBulkEditSelectEnabled($select, enabled) {
                if (enabled) {
                    $select.prop('disabled', false).removeAttr('disabled');
                } else {
                    $select.prop('disabled', true).attr('disabled', 'disabled').val('');
                }
                syncBulkEditSelect($select);
            }

            function registerBulkEditSelectLanguage() {
                if (!$.fn.select2 || !$.fn.select2.amd || registerBulkEditSelectLanguage.done) {
                    return;
                }
                $.fn.select2.amd.define('select2/i18n/psm-bulk-edit', [], function() {
                    return {
                        noResults: function() {
                            return 'No matching options found';
                        }
                    };
                });
                registerBulkEditSelectLanguage.done = true;
            }

            function initBulkEditSelect($select, placeholder) {
                if (!$.fn.select2 || $select.data('select2')) {
                    return;
                }
                registerBulkEditSelectLanguage();
                $select.select2({
                    dropdownParent: $('#bulkEditModal'),
                    width: '100%',
                    placeholder: placeholder,
                    minimumResultsForSearch: 0,
                    language: 'psm-bulk-edit'
                });
            }

            function populateBulkEditSelect($select, items, placeholder) {
                var current = $select.val();
                $select.empty();
                $select.append($('<option>', { value: '', text: placeholder }));
                $.each(items, function(_, item) {
                    $select.append($('<option>', { value: item.id, text: item.name }));
                });
                if (current && $select.find('option[value="' + current + '"]').length) {
                    $select.val(current);
                }
                syncBulkEditSelect($select);
            }

            function populateBulkEditSubCategories() {
                var $select = $('#bulkEditSubCategory');
                var previous = $select.val();
                var limitToCategory = $('#bulkEditChangeCategory').is(':checked') && $('#bulkEditCategory').val();
                var categoryId = limitToCategory ? String($('#bulkEditCategory').val()) : '';

                $select.empty();
                $select.append($('<option>', { value: '', text: '-- Select Sub-Category --' }));

                $.each(bulkEditSubCategories, function(_, subCategory) {
                    if (categoryId && String(subCategory.category_id) !== categoryId) {
                        return;
                    }
                    var label = subCategory.name;
                    if (!categoryId && subCategory.category_name) {
                        label += ' (' + subCategory.category_name + ')';
                    }
                    $select.append($('<option>', { value: subCategory.id, text: label }));
                });

                if (previous && $select.find('option[value="' + previous + '"]').length) {
                    $select.val(previous);
                }
                syncBulkEditSelect($select);
            }

            function resetBulkEditModal() {
                $('#bulkEditChangeCategory, #bulkEditChangeSubCategory, #bulkEditChangeBrand').prop('checked', false);
                setBulkEditSelectEnabled($('#bulkEditCategory'), false);
                setBulkEditSelectEnabled($('#bulkEditSubCategory'), false);
                setBulkEditSelectEnabled($('#bulkEditBrand'), false);
                populateBulkEditSelect($('#bulkEditCategory'), bulkEditCategories, '-- Select Category --');
                populateBulkEditSelect($('#bulkEditBrand'), bulkEditBrands, '-- Select Brand --');
                populateBulkEditSubCategories();
                $('#bulkEditFormError, #bulkEditConfirmError').hide().text('');
                $('#bulkEditConfirmSummary').empty();
                $('#bulkEditModal').removeData('payload');
                showBulkEditFormStep();
            }

            function showBulkEditFormStep() {
                $('#bulkEditFormStep').show();
                $('#bulkEditConfirmStep').hide();
                $('#bulkEditReviewBtn').show();
                $('#bulkEditBackBtn, #bulkEditApplyBtn').hide();
            }

            function selectedProductIds() {
                return currentBulkSelection().ids;
            }

            function collectBulkEditChanges(selectedIds) {
                var payload = { product_ids: selectedIds };
                var changes = [];

                if ($('#bulkEditChangeCategory').is(':checked')) {
                    var categoryId = $('#bulkEditCategory').val();
                    if (!categoryId) {
                        return { error: 'Select a category, or turn off Category.' };
                    }
                    payload.category_id = categoryId;
                    changes.push('Category: ' + $('#bulkEditCategory option:selected').text());
                }

                if ($('#bulkEditChangeSubCategory').is(':checked')) {
                    var subCategoryId = $('#bulkEditSubCategory').val();
                    if (!subCategoryId) {
                        return { error: 'Select a sub-category, or turn off Sub-Category.' };
                    }
                    payload.sub_category_id = subCategoryId;
                    changes.push('Sub-Category: ' + $('#bulkEditSubCategory option:selected').text());
                }

                if ($('#bulkEditChangeBrand').is(':checked')) {
                    var brandId = $('#bulkEditBrand').val();
                    if (!brandId) {
                        return { error: 'Select a brand, or turn off Brand.' };
                    }
                    payload.brand_id = brandId;
                    changes.push('Brand: ' + $('#bulkEditBrand option:selected').text());
                }

                if (changes.length === 0) {
                    return { error: 'Choose at least one field to update.' };
                }

                return { payload: payload, changes: changes };
            }

            populateBulkEditSelect($('#bulkEditCategory'), bulkEditCategories, '-- Select Category --');
            populateBulkEditSelect($('#bulkEditBrand'), bulkEditBrands, '-- Select Brand --');
            populateBulkEditSubCategories();
            initBulkEditSelect($('#bulkEditCategory'), '-- Select Category --');
            initBulkEditSelect($('#bulkEditSubCategory'), '-- Select Sub-Category --');
            initBulkEditSelect($('#bulkEditBrand'), '-- Select Brand --');

            $('#bulkEditChangeCategory').on('change', function() {
                setBulkEditSelectEnabled($('#bulkEditCategory'), $(this).is(':checked'));
                populateBulkEditSubCategories();
            });

            $('#bulkEditCategory').on('change', function() {
                if (syncingBulkEditSelect) {
                    return;
                }
                populateBulkEditSubCategories();
            });

            $('#bulkEditChangeSubCategory').on('change', function() {
                setBulkEditSelectEnabled($('#bulkEditSubCategory'), $(this).is(':checked'));
            });

            $('#bulkEditChangeBrand').on('change', function() {
                setBulkEditSelectEnabled($('#bulkEditBrand'), $(this).is(':checked'));
            });

            $('#bulkEditBtn').on('click', function() {
                var selectedIds = selectedProductIds();
                if (selectedIds.length === 0) {
                    alert('Please select at least one product to edit.');
                    return;
                }

                resetBulkEditModal();
                var countLabel = selectedIds.length === 1 ? '1 Product' : selectedIds.length + ' Products';
                $('#bulkEditModalLabel').text('Bulk Edit ' + countLabel);
                $('#bulkEditCount').text(selectedIds.length);
                $('#bulkEditModal').data('selectedIds', selectedIds);
                $('#bulkEditModal').modal('show');
            });

            $('#bulkEditReviewBtn').on('click', function() {
                var selectedIds = $('#bulkEditModal').data('selectedIds') || selectedProductIds();
                var result = collectBulkEditChanges(selectedIds);
                $('#bulkEditFormError, #bulkEditConfirmError').hide().text('');

                if (result.error) {
                    $('#bulkEditFormError').text(result.error).show();
                    return;
                }

                var countText = selectedIds.length === 1 ? '1 product' : selectedIds.length + ' products';
                $('#bulkEditConfirmQuestion').text('Are you sure you want to update ' + countText + '?');
                var $summary = $('#bulkEditConfirmSummary').empty();
                $.each(result.changes, function(_, change) {
                    $summary.append($('<li>').text(change));
                });
                $('#bulkEditModal').data('payload', result.payload);
                $('#bulkEditFormStep').hide();
                $('#bulkEditConfirmStep').show();
                $('#bulkEditReviewBtn').hide();
                $('#bulkEditBackBtn, #bulkEditApplyBtn').show();
            });

            $('#bulkEditBackBtn').on('click', function() {
                $('#bulkEditConfirmError').hide().text('');
                showBulkEditFormStep();
            });

            $('#bulkEditModal').on('hidden.bs.modal', function() {
                $('#bulkEditCategory, #bulkEditSubCategory, #bulkEditBrand').each(function() {
                    var $select = $(this);
                    if ($select.data('select2')) {
                        $select.select2('close');
                    }
                });
                resetBulkEditModal();
            });

            $('#bulkEditApplyBtn').on('click', function() {
                var payload = $('#bulkEditModal').data('payload');
                if (!payload || !payload.product_ids || payload.product_ids.length === 0) {
                    $('#bulkEditConfirmError').text('Select at least one product.').show();
                    return;
                }

                var $btn = $(this);
                showProductsLoader(
                    'Updating Products',
                    'Updating ' + payload.product_ids.length + ' selected product(s). Please wait...'
                );
                $btn.prop('disabled', true);

                $.ajax({
                    url: '{{ route("admin.products.bulk-update") }}',
                    method: 'POST',
                    data: $.extend({ _token: '{{ csrf_token() }}' }, payload),
                    success: function(response) {
                        if (response.success) {
                            $('#bulkEditModal').modal('hide');
                            alert(response.message || 'Products updated successfully.');
                            clearProductSelection();
                            productsTable.ajax.reload(function() {
                                $('#selectAll').prop('checked', false);
                                updateBulkButtons();
                            }, false);
                        } else {
                            $('#bulkEditConfirmError').text(response.message || 'Failed to update products.').show();
                        }
                    },
                    error: function(xhr) {
                        var message = 'An error occurred while updating products.';
                        if (xhr.responseJSON && xhr.responseJSON.message) {
                            message = xhr.responseJSON.message;
                        }
                        $('#bulkEditConfirmError').text(message).show();
                    },
                    complete: function() {
                        hideProductsLoader();
                        $btn.prop('disabled', false);
                        updateBulkButtons();
                    }
                });
            });

            // Merge product functionality
            var selectedCorrectProductId = null;
            var searchTimeout = null;

            // Reset merge button when modal is shown (safety check)
            $('#mergeProductModal').on('show.bs.modal', function() {
                $('#confirmMergeBtn').prop('disabled', true).html('Confirm Merge');
            });

            $(document).on('click', '.merge-product-btn', function() {
                var productId = $(this).data('product-id');
                var productName = $(this).data('product-name');
                var psmCode = $(this).data('psm-code');

                $('#wrongProductId').val(productId);
                $('#mergeProductName').text(productName);
                $('#mergeProductPsmCode').text(psmCode || 'N/A');
                $('#productSearch').val('');
                $('#productSearchResults').html('');
                selectedCorrectProductId = null;

                // Reset button to initial state
                $('#confirmMergeBtn').prop('disabled', true).html('Confirm Merge');

                $('#mergeProductModal').modal('show');
            });

            // Product search with debounce
            $('#productSearch').on('input', function() {
                clearTimeout(searchTimeout);
                var searchTerm = $(this).val();
                var excludeId = $('#wrongProductId').val();

                if (searchTerm.length < 2) {
                    $('#productSearchResults').html('');
                    return;
                }

                searchTimeout = setTimeout(function() {
                    $.ajax({
                        url: '{{ route("admin.products.search") }}',
                        method: 'GET',
                        data: {
                            search: searchTerm,
                            exclude_id: excludeId
                        },
                        success: function(response) {
                            var html = '';
                            if (response.length === 0) {
                                html = '<div class="alert alert-warning">No products found.</div>';
                            } else {
                                html = '<div class="list-group">';
                                response.forEach(function(product) {
                                    html += '<a href="#" class="list-group-item list-group-item-action product-select-item" data-product-id="' + product.id + '">';
                                    html += '<div class="d-flex w-100 justify-content-between">';
                                    html += '<h6 class="mb-1">' + product.model + '</h6>';
                                    html += '</div>';
                                    html += '<p class="mb-1"><small>PSM Code: ' + product.psm_code + ' | Brand: ' + product.brand + ' | Category: ' + product.category + '</small></p>';
                                    html += '</a>';
                                });
                                html += '</div>';
                            }
                            $('#productSearchResults').html(html);
                        },
                        error: function() {
                            $('#productSearchResults').html('<div class="alert alert-danger">Error searching products.</div>');
                        }
                    });
                }, 300);
            });

            // Select product from search results
            $(document).on('click', '.product-select-item', function(e) {
                e.preventDefault();
                $('.product-select-item').removeClass('active');
                $(this).addClass('active');
                selectedCorrectProductId = $(this).data('product-id');
                $('#confirmMergeBtn').prop('disabled', false);
            });

            // Confirm merge
            $('#confirmMergeBtn').on('click', function() {
                if (!selectedCorrectProductId) {
                    alert('Please select a product to merge into.');
                    return;
                }

                var wrongProductId = $('#wrongProductId').val();
                var productName = $('#mergeProductName').text();

                if (!confirm('Are you sure you want to merge "' + productName + '" into the selected product? This action cannot be undone.')) {
                    return;
                }

                var $btn = $(this);
                showProductsLoader(
                    'Merging Products',
                    'Merging "' + productName + '" into the selected product. Please wait...'
                );
                $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Merging...');

                var mergeUrl = '{{ url("admin/products") }}/' + wrongProductId + '/merge';
                $.ajax({
                    url: mergeUrl,
                    method: 'POST',
                    data: {
                        _token: '{{ csrf_token() }}',
                        correct_product_id: selectedCorrectProductId
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Product merged successfully!');
                            $('#mergeProductModal').modal('hide');
                            productsTable.ajax.reload();
                        } else {
                            alert('Error: ' + (response.message || 'Failed to merge products.'));
                            $btn.prop('disabled', false).html('Confirm Merge');
                        }
                    },
                    error: function(xhr) {
                        var message = 'An error occurred while merging products.';
                        if (xhr.responseJSON && xhr.responseJSON.message) {
                            message = xhr.responseJSON.message;
                        }
                        alert(message);
                        $btn.prop('disabled', false).html('Confirm Merge');
                    },
                    complete: function() {
                        hideProductsLoader();
                    }
                });
            });
        });
    </script>
@stop

