{{--
    Global Admin Confirmation Modal
    ────────────────────────────────
    Intercepts ALL native confirm() calls inside the admin layout
    and replaces them with a styled Bootstrap modal.

    Include this partial ONCE in the admin layout (layouts.admin).
    It does NOT require any changes to existing blade templates.

    The redesigned admin (admin.ui v2) renders the same modal in its own style and runs the same logic from
    resources/admin/modules/legacy.ts (no inline script); the confirm button is named after the action
    when the question starts with one of the verbs in data-verbs ("Delete all unused tags?" -> "Delete").
    Only questions with a word of data-destructive ("Delete", "Clear", "permanently", ...) get the red button and
    "This action cannot be undone."; Cancel takes the focus when the modal opens.

    Strategy:
    1. Override window.confirm() to always return false (prevents form submit)
    2. Store the confirm message and the originating form/button
    3. Show a Bootstrap modal with the message
    4. On "Confirm", re-submit the original form, bypassing confirm()
--}}
@if (\App\Support\AdminPreferences::usesNewAdmin())
    @php
        $confirmVerbs = collect(['Delete', 'Remove', 'Clear', 'Truncate', 'Revoke', 'Ban', 'Block', 'Unban', 'Reset', 'Restore', 'Run', 'Flush', 'Purge', 'Retry', 'Merge', 'Send', 'Publish', 'Verify', 'Approve', 'Reject', 'Stop', 'Start', 'Pause', 'Resume', 'Import', 'Apply', 'Enable', 'Disable', 'Restart', 'Optimize', 'Migrate'])
            ->flatMap(fn (string $verb) => [$verb.'='.__($verb), __($verb).'='.__($verb)])
            ->unique()
            ->implode('|');
        $destructiveVerbs = collect(['Delete', 'Remove', 'Clear', 'Truncate', 'Revoke', 'Ban', 'Block', 'Reset', 'Restore', 'Flush', 'Purge', 'Merge', 'Migrate', 'Empty', 'Drop', 'Wipe', 'Overwrite', 'Permanently'])
            ->flatMap(fn (string $verb) => [$verb, __($verb)])
            ->unique()
            ->implode('|');
    @endphp
    <div class="modal fade" tabindex="-1" id="admin_confirm" aria-hidden="true" aria-labelledby="admin_confirm_title" data-verbs="{{ $confirmVerbs }}" data-destructive="{{ $destructiveVerbs }}">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body ad-dialog-b">
                    <span class="ad-sym is-danger" aria-hidden="true">{{ \App\Support\AdminTheme::icon('alert') }}</span>
                    <div class="ad-dialog-text">
                        <h2 class="modal-title" id="admin_confirm_title">{{ __('Confirm action') }}</h2>
                        <p class="mb-0" id="admin_confirm_message">{{ __('This action cannot be undone.') }}</p>
                    </div>
                </div>
                <div class="modal-footer justify-content-end">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                    <button type="button" class="btn btn-danger" id="admin_confirm_submit"><span>{{ __('Confirm') }}</span></button>
                </div>
            </div>
        </div>
    </div>
@else
    <div class="modal fade" tabindex="-1" id="admin_confirm" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="admin_confirm_title">{{ __('Confirm action') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{__('Close')}}"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger mb-0">
                        <i class="fa-solid fa-triangle-exclamation me-1"></i>
                        <span id="admin_confirm_message">{{ __('This action cannot be undone.') }}</span>
                    </div>
                </div>
                <div class="modal-footer d-flex justify-content-between">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                    <button type="button" class="btn btn-danger" id="admin_confirm_submit">
                        <i class="fa-solid fa-check me-1"></i>
                        <span>{{ __('Confirm') }}</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

<script>
(function() {
    // Wait for Bootstrap to be loaded
    document.addEventListener('DOMContentLoaded', function() {
        const modalEl  = document.getElementById('admin_confirm');
        if (!modalEl || typeof bootstrap === 'undefined') return;

        const bsModal   = new bootstrap.Modal(modalEl);
        const titleEl   = document.getElementById('admin_confirm_title');
        const messageEl = document.getElementById('admin_confirm_message');
        const submitBtn = document.getElementById('admin_confirm_submit');

        let pendingForm    = null;
        let pendingOnclick = null;
        let confirmed      = false;

        // ── Override native confirm() ──
        const originalConfirm = window.confirm;
        window.confirm = function(msg) {
            if (confirmed) {
                // This is a re-trigger after user clicked "Confirm" in our modal
                confirmed = false;
                return true;
            }

            // Store the message and show our modal instead
            messageEl.textContent = msg || '{{ __("Are you sure?") }}';
            titleEl.textContent   = '{{ __("Confirm action") }}';

            // Find the form that triggered this confirm
            // The confirm() call happens inside onsubmit or onclick,
            // so we can find the active element or form via document.activeElement
            const activeEl = document.activeElement;
            if (activeEl) {
                // If it was triggered from a button[type="button"], link, or element with onclick, re-click the element
                if (activeEl.getAttribute('onclick') || (activeEl.tagName === 'BUTTON' && activeEl.getAttribute('type') === 'button') || activeEl.tagName === 'A') {
                    pendingOnclick = activeEl;
                } else {
                    pendingForm = activeEl.closest('form') || activeEl.form;
                }
            }

            // Show the modal
            bsModal.show();

            // Return false to prevent the original form submission
            return false;
        };

        // ── Handle "Confirm" button click ──
        submitBtn.addEventListener('click', function() {
            bsModal.hide();

            if (pendingForm) {
                const form = pendingForm;
                pendingForm = null;

                // Set flag so our overridden confirm() returns true
                confirmed = true;

                // Find and click the submit button to re-trigger the full flow
                const submitButton = form.querySelector('button[type="submit"], input[type="submit"]');
                if (submitButton) {
                    submitButton.click();
                } else {
                    // No explicit submit button, directly submit
                    confirmed = true;
                    form.submit();
                }
            } else if (pendingOnclick) {
                const el = pendingOnclick;
                pendingOnclick = null;

                // Set flag so confirm() returns true
                confirmed = true;
                el.click();
            }
        });

        // ── Reset state when modal is closed without confirming ──
        modalEl.addEventListener('hidden.bs.modal', function() {
            pendingForm    = null;
            pendingOnclick = null;
            confirmed      = false;
        });

        // ── Also support Alpine.js-driven modals ──
        // For pages that use data-bs-toggle="modal" + @click="adminConfirm={...}"
        modalEl.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            if (!button) return; // Programmatic open (from interceptor above)

            // Check if this button has Alpine adminConfirm data
            try {
                if (typeof Alpine !== 'undefined') {
                    const scope = Alpine.$data(button);
                    if (scope && scope.adminConfirm && scope.adminConfirm.action) {
                        const ac = scope.adminConfirm;

                        titleEl.textContent   = ac.title || '{{ __("Confirm action") }}';
                        messageEl.textContent = ac.warning || '{{ __("This action cannot be undone.") }}';

                        // Build and submit a form dynamically
                        submitBtn.onclick = function() {
                            const form = document.createElement('form');
                            form.method = 'POST';
                            form.action = ac.action;
                            form.style.display = 'none';

                            // CSRF token
                            const csrf = document.querySelector('meta[name="csrf-token"]');
                            if (csrf) {
                                const csrfInput = document.createElement('input');
                                csrfInput.type  = 'hidden';
                                csrfInput.name  = '_token';
                                csrfInput.value = csrf.content;
                                form.appendChild(csrfInput);
                            }

                            // Method spoofing
                            if (ac.method && ac.method !== 'POST') {
                                const methodInput = document.createElement('input');
                                methodInput.type  = 'hidden';
                                methodInput.name  = '_method';
                                methodInput.value = ac.method;
                                form.appendChild(methodInput);
                            }

                            document.body.appendChild(form);
                            form.submit();
                        };
                    }
                }
            } catch(e) {
                // Alpine not available or not initialized, ignore
            }
        });

        // Reset onclick handler when modal closes
        modalEl.addEventListener('hidden.bs.modal', function() {
            submitBtn.onclick = null;
        });
    });
})();
</script>
@endif
