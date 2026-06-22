<style>
    .select2-container--default .select2-selection--single {
        height: calc(2.25rem + 2px);
        border: 1px solid #ced4da;
        border-radius: 0.25rem;
    }
    .select2-container--default .select2-selection--single .select2-selection__rendered {
        line-height: calc(2.25rem + 2px);
        padding-left: 0.75rem;
    }
    .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: calc(2.25rem + 2px);
    }
    .select2-container {
        width: 100% !important;
    }
</style>
<script>
    $(document).ready(function () {
        function formatProductResult(product) {
            var brand = product.brand && product.brand !== '—' ? product.brand + ' ' : '';
            var text = brand + (product.model || '');
            if (product.psm_code && product.psm_code !== '—') {
                text += ' (' + product.psm_code + ')';
            }
            return text;
        }

        $('.product-search-select').each(function () {
            var $select = $(this);
            if ($select.data('select2')) {
                return;
            }

            $select.select2({
                ajax: {
                    url: $select.data('search-url'),
                    dataType: 'json',
                    delay: 300,
                    data: function (params) {
                        return { search: params.term || '' };
                    },
                    processResults: function (data) {
                        return {
                            results: (data || []).map(function (product) {
                                return {
                                    id: product.id,
                                    text: formatProductResult(product),
                                };
                            }),
                        };
                    },
                    cache: true,
                },
                minimumInputLength: 2,
                placeholder: 'Type to search products…',
                allowClear: true,
                width: '100%',
            });
        });
    });
</script>
