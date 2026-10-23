<div class="modal modal-lg fade" tabindex="-1" id="crop" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">{{ __('Resize picture') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{__('Close')}}"></button>
            </div>
            <div class="modal-body">
                <div class="img-container">
                    <img style="display: block;max-width: 100%;" id="cropped_image" src="{{asset('images/placeholders/no-image.svg')}}" alt="cropped image">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                <button id="crop_button" type="button" class="btn btn-primary">{{ __('Resize') }}</button>
            </div>
        </div>
    </div>
</div>

<script>
    let cropButtonHandler = null;

    document.getElementById('crop').addEventListener('shown.bs.modal', event => {

        const config = JSON.parse(event.relatedTarget.dataset.config);

        const croppedImage = document.getElementById('cropped_image');
        const name = event.relatedTarget.dataset.name;
        const input = document.querySelector(`input[name="${name}"]:not([readonly])`);
        const cropButton = document.getElementById('crop_button');

        if (window.cropper) {
            window.cropper.destroy();
            window.cropper = null;
        }

        if (cropButtonHandler) {
            cropButton.removeEventListener('click', cropButtonHandler);
            cropButtonHandler = null;
        }

        window.cropper = new Cropper(croppedImage, {
            aspectRatio: config.aspectRatio,
            minCropBoxWidth: config.minWidth,
            minCropBoxHeight: config.minHeight,
            viewMode: 2,
            modal: true,
            background: true,
            center: true,
            autoCrop: true,
            autoCropArea: config.cropArea,
            dragMode: 'move',
            responsive: true,
            cropBoxResizable: config.resizeable,
            guides : false,
            ready : () => {
                if(config.rounded) {
                    document.querySelector('.cropper-view-box').style.borderRadius = '50%';
                    document.querySelector('.cropper-face').style.borderRadius = '50%';
                }
            }
        });

        cropButtonHandler = e => {
            cropButton.disabled = true;

            const finishCrop = () => {
                cropButton.disabled = false;
                const modal = window.bootstrap?.Modal?.getInstance(document.getElementById('crop'));
                modal?.hide();
            };

            if (!window.cropper || !input) {
                finishCrop();
                return;
            }

            const canvas = window.cropper.getCroppedCanvas({
                width: config.width,
                height: config.height,
            });

            if (!canvas) {
                finishCrop();
                return;
            }

            canvas.toBlob(function (blob) {
                if (!blob) {
                    finishCrop();
                    return;
                }

                try {
                    const file = new File([blob], "img.jpg", {type: "image/jpeg", lastModified: new Date().getTime()});

                    if (typeof DataTransfer === 'function') {
                        const container = new DataTransfer();
                        container.items.add(file);
                        input.files = container.files;
                        window.dispatchEvent(new CustomEvent(`cropped-${name}`, { detail: file }));
                    } else if (input.files?.[0]) {
                        // Older Safari versions do not allow assigning FileList.
                        // Keep the original selected image so the profile update
                        // still saves instead of silently submitting nothing.
                        window.dispatchEvent(new CustomEvent(`cropped-${name}`, { detail: input.files[0] }));
                    }
                } finally {
                    finishCrop();
                }
            }, 'image/jpeg', 0.92);
        };

        cropButton.addEventListener('click', cropButtonHandler, { once: true })
    })

    document.getElementById('crop').addEventListener('hidden.bs.modal', event => {
        const cropButton = document.getElementById('crop_button');

        if (cropButtonHandler) {
            cropButton.removeEventListener('click', cropButtonHandler);
            cropButtonHandler = null;
        }

        if (window.cropper) {
            window.cropper.destroy();
            window.cropper = null;
        }
    })
</script>
