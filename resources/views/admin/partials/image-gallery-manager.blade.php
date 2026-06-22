@php
    $entityType = $entityType ?? 'product';
    $readOnly = $readOnly ?? false;

    if ($entityType === 'product') {
        $storeRoute = route('admin.products.master-images.store', $entity);
        $reorderRoute = route('admin.products.master-images.reorder', $entity);
        $primaryRoute = fn ($image) => route('admin.products.master-images.primary', [$entity, $image]);
        $destroyRoute = fn ($image) => route('admin.products.master-images.destroy', [$entity, $image]);
        $updateRoute = fn ($image) => route('admin.products.master-images.update', [$entity, $image]);
    } else {
        $storeRoute = route('admin.equipment.images.store', $entity);
        $reorderRoute = route('admin.equipment.images.reorder', $entity);
        $primaryRoute = fn ($image) => route('admin.equipment.images.primary', [$entity, $image]);
        $destroyRoute = fn ($image) => route('admin.equipment.images.destroy', [$entity, $image]);
        $updateRoute = fn ($image) => route('admin.equipment.images.update', [$entity, $image]);
    }
@endphp

<div class="card {{ $cardClass ?? '' }}">
    <div class="card-header">
        <h3 class="card-title">{{ $title ?? 'Images' }}</h3>
    </div>
    <div class="card-body">
        @if($images->count() > 0)
            <div class="row mb-3" id="image-gallery-{{ $entityType }}-{{ $entity->id }}">
                @foreach($images as $image)
                    <div class="col-md-4 col-sm-6 mb-3 text-center image-gallery-item" data-image-id="{{ $image->id }}">
                        <img src="{{ \App\Support\InventoryImageManagementService::publicUrl($image->image_path) }}"
                             class="img-fluid img-thumbnail mb-1"
                             alt="Image">
                        @if($image->is_primary)
                            <span class="badge badge-success d-block mb-1">Primary</span>
                        @endif
                        @if(!empty($image->source))
                            <span class="badge badge-secondary d-block mb-1">{{ $image->source }}</span>
                        @endif
                        @if(isset($image->sort_order))
                            <small class="text-muted d-block mb-1">Order: {{ $image->sort_order }}</small>
                        @endif

                        @unless($readOnly)
                            <div class="btn-group btn-group-sm mb-1" role="group">
                                <button type="button" class="btn btn-outline-secondary btn-move-up" title="Move up">
                                    <i class="fas fa-arrow-up"></i>
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-move-down" title="Move down">
                                    <i class="fas fa-arrow-down"></i>
                                </button>
                            </div>

                            @if(!$image->is_primary)
                                <form action="{{ $primaryRoute($image) }}" method="POST" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-xs btn-outline-primary">Set primary</button>
                                </form>
                            @endif

                            <form action="{{ $updateRoute($image) }}" method="POST" enctype="multipart/form-data" class="mt-1">
                                @csrf
                                @method('PUT')
                                <div class="custom-file custom-file-sm">
                                    <input type="file" name="image" class="custom-file-input custom-file-input-sm" accept="image/*">
                                    <label class="custom-file-label">Replace file</label>
                                </div>
                                <input type="text" name="image_path" class="form-control form-control-sm mt-1"
                                       placeholder="Or new URL/path">
                                <button type="submit" class="btn btn-xs btn-outline-warning mt-1">Update</button>
                            </form>

                            <form action="{{ $destroyRoute($image) }}" method="POST" class="d-inline mt-1"
                                  onsubmit="return confirm('Delete this image?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-xs btn-outline-danger">Delete</button>
                            </form>
                        @endunless
                    </div>
                @endforeach
            </div>

            @unless($readOnly)
                <form action="{{ $reorderRoute }}" method="POST" id="image-reorder-form-{{ $entityType }}-{{ $entity->id }}">
                    @csrf
                    <div id="image-reorder-inputs-{{ $entityType }}-{{ $entity->id }}"></div>
                    <button type="submit" class="btn btn-sm btn-secondary">
                        <i class="fas fa-sort"></i> Save order
                    </button>
                </form>
            @endunless
        @else
            <p class="text-muted">{{ $emptyMessage ?? 'No images yet.' }}</p>
        @endif

        @unless($readOnly)
            <hr>
            <h6 class="mb-2">Add image</h6>
            <form action="{{ $storeRoute }}" method="POST" enctype="multipart/form-data">
                @csrf
                <div class="form-group">
                    <label>Upload file</label>
                    <input type="file" name="image" class="form-control-file" accept="image/jpeg,image/png,image/gif,image/webp">
                    <small class="form-text text-muted">Max 5MB. JPG, PNG, GIF, or WebP.</small>
                </div>
                <div class="form-group">
                    <label>Or image URL / path</label>
                    <input type="text" name="image_path" class="form-control @error('image_path') is-invalid @enderror"
                           value="{{ old('image_path') }}" placeholder="https://... or images/...">
                    @error('image_path')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="fas fa-plus"></i> Add image
                </button>
            </form>
        @endunless
    </div>
</div>

@unless($readOnly)
    @if($images->count() > 0)
            <script>
                (function () {
                    var galleryId = 'image-gallery-{{ $entityType }}-{{ $entity->id }}';
                    var formId = 'image-reorder-form-{{ $entityType }}-{{ $entity->id }}';
                    var inputsId = 'image-reorder-inputs-{{ $entityType }}-{{ $entity->id }}';

                    function syncReorderInputs() {
                        var container = document.getElementById(inputsId);
                        var gallery = document.getElementById(galleryId);
                        if (!container || !gallery) return;
                        container.innerHTML = '';
                        gallery.querySelectorAll('.image-gallery-item').forEach(function (item) {
                            var input = document.createElement('input');
                            input.type = 'hidden';
                            input.name = 'order[]';
                            input.value = item.getAttribute('data-image-id');
                            container.appendChild(input);
                        });
                    }

                    function moveItem(item, direction) {
                        var gallery = document.getElementById(galleryId);
                        if (!gallery || !item) return;
                        if (direction < 0 && item.previousElementSibling) {
                            gallery.insertBefore(item, item.previousElementSibling);
                        } else if (direction > 0 && item.nextElementSibling) {
                            gallery.insertBefore(item.nextElementSibling, item);
                        }
                        syncReorderInputs();
                    }

                    document.getElementById(galleryId).addEventListener('click', function (e) {
                        var item = e.target.closest('.image-gallery-item');
                        if (!item) return;
                        if (e.target.closest('.btn-move-up')) {
                            e.preventDefault();
                            moveItem(item, -1);
                        }
                        if (e.target.closest('.btn-move-down')) {
                            e.preventDefault();
                            moveItem(item, 1);
                        }
                    });

                    document.getElementById(formId).addEventListener('submit', syncReorderInputs);
                    syncReorderInputs();
                })();
            </script>
    @endif
@endunless
