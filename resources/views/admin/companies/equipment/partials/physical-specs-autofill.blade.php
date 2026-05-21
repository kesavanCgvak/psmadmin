<script>
    $(document).ready(function () {
        var specsUrlTemplate = @json(route('admin.ajax.product-inventory-specs', ['product' => '__ID__']));
        var lastLoadedProductId = null;

        function fillPhysicalSpecFields(data) {
            if (!data) {
                return;
            }
            $('#height').val(data.height !== null && data.height !== undefined ? data.height : '');
            $('#width').val(data.width !== null && data.width !== undefined ? data.width : '');
            $('#length').val(data.length !== null && data.length !== undefined ? data.length : '');
            $('#weight').val(data.weight !== null && data.weight !== undefined ? data.weight : '');
            $('#linear_unit_id').val(data.linear_unit_id || '');
            $('#weight_unit_id').val(data.weight_unit_id || '');
            $('#country_of_origin').val(data.country_of_origin || '');
            $('#hsn_code').val(data.hsn_code || '');
        }

        function loadSpecsFromProduct(productId, force) {
            if (!productId) {
                return;
            }
            if (!force && String(productId) === String(lastLoadedProductId)) {
                return;
            }

            var url = specsUrlTemplate.replace('__ID__', productId);
            $.get(url, function (response) {
                if (response && response.success && response.data) {
                    fillPhysicalSpecFields(response.data);
                    lastLoadedProductId = productId;
                }
            });
        }

        $('#product_id').on('change select2:select', function () {
            var productId = $(this).val();
            if (!productId) {
                lastLoadedProductId = null;
                return;
            }
            loadSpecsFromProduct(productId, true);
        });

        @if(old('product_id'))
            loadSpecsFromProduct(@json(old('product_id')), true);
        @endif
    });
</script>
