<script>
var ADMIN_LIST_BATCH = 500;

function attachAdminAllBatches(table) {
    if (!table || !table.settings || table.settings().length === 0) {
        return;
    }

    var settings = table.settings()[0];
    if (settings._adminAllBatches || !settings.oFeatures || !settings.oFeatures.bPaginate) {
        return;
    }
    settings._adminAllBatches = true;

    if (!document.getElementById('admin-all-browser-style')) {
        var style = document.createElement('style');
        style.id = 'admin-all-browser-style';
        style.textContent = ''
            + '.admin-all-browser{margin-bottom:.75rem;padding:.75rem 1rem;background:#f8f9fa;border:1px solid #e3e6ea;border-radius:.25rem;}'
            + '.admin-all-browser__controls{display:flex;flex-wrap:wrap;align-items:center;gap:.4rem;}'
            + '.admin-all-active .dataTables_paginate{display:none;}'
            + '.admin-all-sentinel{height:1px;}';
        document.head.appendChild(style);
    }

    var batch = ADMIN_LIST_BATCH;
    var serverSide = !!settings.oFeatures.bServerSide;
    var allMode = false;
    var allStart = 0;
    var allTotal = 0;
    var allLoading = false;
    var clamping = false;
    var userScrolled = false;
    var lastScrollY = window.pageYOffset || document.documentElement.scrollTop || 0;
    var lastSearch = table.search();
    var lastOrder = JSON.stringify(table.order());
    var lastPayload = null;
    var tableId = settings.sTableId || 'datatable';
    var $wrapper = $(settings.nTableWrapper);
    var $bar = $(
        '<div class="admin-all-browser" style="display:none;" aria-live="polite">'
        + '<p class="admin-all-status mb-2"></p>'
        + '<div class="admin-all-browser__controls">'
        + '<button type="button" class="btn btn-default btn-sm admin-all-first">First</button>'
        + '<button type="button" class="btn btn-default btn-sm admin-all-prev">Previous ' + batch + '</button>'
        + '<button type="button" class="btn btn-default btn-sm admin-all-next">Next ' + batch + '</button>'
        + '<button type="button" class="btn btn-default btn-sm admin-all-last">Last</button>'
        + '<label class="mb-0">Jump to row '
        + '<input type="number" class="form-control form-control-sm d-inline-block admin-all-jump" min="1" step="' + batch + '" style="width:7rem;">'
        + '</label>'
        + '<button type="button" class="btn btn-default btn-sm admin-all-go">Go</button>'
        + '</div>'
        + '<p class="text-muted mb-0 mt-2"><small>Search, filters, and sorting apply to the full list. When more than '
        + batch + ' rows match, All loads them ' + batch + ' at a time. Lists of ' + batch + ' or fewer load in one view.</small></p>'
        + '</div>'
    );
    var $sentinel = $('<div class="admin-all-sentinel" style="display:none;"></div>');
    $wrapper.prepend($bar);
    $wrapper.append($sentinel);

    function needsBatches() {
        return allMode && allTotal > batch;
    }

    function updateUi() {
        if (!needsBatches()) {
            $bar.hide();
            $sentinel.hide();
            $bar.find('.admin-all-status').text('');
            $wrapper.toggleClass('admin-all-active', allMode);
            return;
        }

        $wrapper.addClass('admin-all-active');
        $bar.show();
        $sentinel.show();
        $wrapper.find('.dataTables_length select').val('-1');

        var shown = serverSide
            ? ((settings.json && settings.json.data) ? settings.json.data.length : 0)
            : Math.min(batch, Math.max(allTotal - allStart, 0));
        var from = allTotal === 0 || shown === 0 ? 0 : allStart + 1;
        var to = shown === 0 ? 0 : allStart + shown;
        var batchCount = Math.ceil(allTotal / batch);
        var batchNumber = Math.floor(allStart / batch) + 1;
        $bar.find('.admin-all-status').text(
            'Showing rows ' + from + '–' + to + ' of ' + allTotal + ' (batch ' + batchNumber + ' of ' + batchCount + ').'
        );
        $wrapper.find('.dataTables_info').text('Showing ' + from + ' to ' + to + ' of ' + allTotal + ' entries');
        $bar.find('.admin-all-jump').attr('max', Math.max(allTotal, 1));
        $bar.find('.admin-all-first, .admin-all-prev').prop('disabled', allStart <= 0);
        $bar.find('.admin-all-next, .admin-all-last').prop('disabled', allStart + batch >= allTotal);
        $bar.find('.admin-all-go').prop('disabled', false);
    }

    function moveWindow(nextStart) {
        if (!allMode || allLoading || !needsBatches()) {
            return;
        }
        if (nextStart < 0) {
            nextStart = 0;
        }
        if (allTotal > 0 && nextStart >= allTotal) {
            return;
        }
        nextStart = Math.floor(nextStart / batch) * batch;
        if (nextStart === allStart) {
            return;
        }
        allStart = nextStart;
        userScrolled = false;
        if (serverSide) {
            allLoading = true;
            $bar.find('button').prop('disabled', true);
            if ($bar[0] && $bar[0].scrollIntoView) {
                $bar[0].scrollIntoView({ block: 'start' });
            }
            table.ajax.reload(null, false);
            return;
        }
        settings._iDisplayStart = allStart;
        settings._iDisplayLength = batch;
        table.draw(false);
    }

    table.on('stateSaveParams.dt.adminAll', function (e, s, data) {
        if (allMode) {
            data.length = -1;
        }
    });

    table.on('length.dt.adminAll', function (e, s, len) {
        if (parseInt(len, 10) === -1) {
            allMode = true;
            allStart = 0;
            if (!serverSide) {
                var visible = table.page.info().recordsDisplay;
                allTotal = visible;
                if (visible > batch) {
                    settings._iDisplayLength = batch;
                    settings._iDisplayStart = 0;
                }
            }
            return;
        }
        allMode = false;
        allStart = 0;
        updateUi();
    });

    table.on('preXhr.dt.adminAll', function (e, s, data) {
        if (Number(data.length) === -1) {
            allMode = true;
        }
        var payload = $.extend(true, {}, data);
        delete payload.start;
        delete payload.length;
        delete payload.draw;
        delete payload._;
        var signature = JSON.stringify(payload);
        if (lastPayload !== null && signature !== lastPayload && allMode) {
            allStart = 0;
        }
        lastPayload = signature;
        if (!allMode) {
            return;
        }
        data.length = batch;
        data.start = allStart;
    });

    table.on('preDraw.dt.adminAll', function () {
        if (serverSide || !allMode) {
            return;
        }
        var total = table.page.info().recordsDisplay;
        allTotal = total;
        if (total <= batch) {
            settings._iDisplayLength = -1;
            settings._iDisplayStart = 0;
            allStart = 0;
            return;
        }
        if (allStart >= total) {
            allStart = Math.floor((total - 1) / batch) * batch;
        }
        settings._iDisplayLength = batch;
        settings._iDisplayStart = allStart;
    });

    table.on('search.dt.adminAll', function () {
        var search = table.search();
        if (search === lastSearch) {
            return;
        }
        lastSearch = search;
        if (allMode) {
            allStart = 0;
        }
    });

    table.on('order.dt.adminAll', function () {
        var order = JSON.stringify(table.order());
        if (order === lastOrder) {
            return;
        }
        lastOrder = order;
        if (allMode) {
            allStart = 0;
        }
    });

    table.on('draw.dt.adminAll', function () {
        allLoading = false;
        if (serverSide) {
            var json = settings.json || {};
            allTotal = typeof json.recordsFiltered === 'number' ? json.recordsFiltered : (table.page.info().recordsDisplay || 0);
        } else if (allMode) {
            allTotal = table.page.info().recordsDisplay;
        }

        if (serverSide && allMode && allTotal > 0 && allStart >= allTotal && !clamping) {
            clamping = true;
            allStart = Math.floor((allTotal - 1) / batch) * batch;
            table.ajax.reload(function () {
                clamping = false;
            }, false);
            return;
        }

        updateUi();
    });

    table.on('xhr.dt.adminAll', function (e, s, json, xhr) {
        allLoading = false;
        if (xhr && xhr.status >= 400) {
            $bar.find('.admin-all-go').prop('disabled', false);
            updateUi();
        }
    });

    $bar.on('click', '.admin-all-first', function () {
        moveWindow(0);
    });
    $bar.on('click', '.admin-all-prev', function () {
        moveWindow(allStart - batch);
    });
    $bar.on('click', '.admin-all-next', function () {
        moveWindow(allStart + batch);
    });
    $bar.on('click', '.admin-all-last', function () {
        if (allTotal < 1) {
            return;
        }
        moveWindow(Math.floor((allTotal - 1) / batch) * batch);
    });
    $bar.on('click', '.admin-all-go', function () {
        var raw = parseInt($bar.find('.admin-all-jump').val(), 10);
        if (isNaN(raw)) {
            return;
        }
        raw = Math.min(Math.max(raw, 1), Math.max(allTotal, 1));
        moveWindow(Math.floor((raw - 1) / batch) * batch);
    });
    $bar.on('keydown', '.admin-all-jump', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            $bar.find('.admin-all-go').trigger('click');
        }
    });

    if (window.IntersectionObserver) {
        var observer = new IntersectionObserver(function (entries) {
            if (!entries[0] || !entries[0].isIntersecting) {
                return;
            }
            if (!needsBatches() || allLoading || !userScrolled || allStart + batch >= allTotal) {
                return;
            }
            moveWindow(allStart + batch);
        }, { threshold: 0.6 });
        observer.observe($sentinel[0]);
    }

    window.addEventListener('scroll', function () {
        var y = window.pageYOffset || document.documentElement.scrollTop || 0;
        if (y > lastScrollY + 8) {
            userScrolled = true;
        }
        lastScrollY = y;
    }, { passive: true });

    if ($(settings.nTable).data('adminRestoreAll')) {
        var $select = $wrapper.find('.dataTables_length select');
        $select.val('-1');
        $select.trigger('change');
    }
}
</script>
