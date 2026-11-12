<?php
/**
 * Shared "record delivery attempt" modal.
 *
 * Expects: $reasons (code => label), $returnTo ('tasks'|'desk'|'shipment').
 * Trigger buttons carry data-shipment-id, data-tracking and data-bs-target="#attemptModal".
 */
?>
<div class="modal fade" id="attemptModal" tabindex="-1" aria-labelledby="attemptModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="#" id="attemptForm" data-base="<?= esc(site_url('delivery'), 'attr') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="return_to" value="<?= esc($returnTo ?? 'desk', 'attr') ?>">
            <div class="modal-header">
                <h5 class="modal-title" id="attemptModalLabel"><i class="bi bi-clipboard-check me-2"></i>Record delivery attempt</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3">Shipment <strong class="ts-mono" id="attemptTracking">—</strong></p>

                <div class="ts-mode" role="radiogroup" aria-label="Attempt outcome">
                    <input type="radio" name="outcome" id="att_delivered" value="delivered" checked><label for="att_delivered"><i class="bi bi-check2-circle me-1"></i> Delivered</label>
                    <input type="radio" name="outcome" id="att_failed" value="failed"><label for="att_failed"><i class="bi bi-x-octagon me-1"></i> Failed</label>
                </div>

                <div id="attDeliveredHint" class="ts-hint mb-2">After saving you will capture the proof of delivery to complete the shipment.</div>

                <div id="attFailedBox" hidden>
                    <div class="mb-3">
                        <label class="form-label ts-required" for="att_reason">Failure reason</label>
                        <select name="failure_reason" id="att_reason" class="form-select">
                            <option value="">Select reason…</option>
                            <?php foreach ($reasons as $code => $text): ?>
                                <option value="<?= esc($code, 'attr') ?>"><?= esc($text) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="create_ndr" value="1" id="att_ndr" checked>
                        <label class="form-check-label" for="att_ndr">Open an NDR case for follow-up</label>
                    </div>
                </div>

                <label class="form-label" for="att_remarks">Remarks</label>
                <textarea name="remarks" id="att_remarks" rows="2" maxlength="500" class="form-control" placeholder="Optional notes from the field"></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-soft" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-primary">Save attempt</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var modal = document.getElementById('attemptModal');
    if (!modal) { return; }
    var form = document.getElementById('attemptForm');
    var failedBox = document.getElementById('attFailedBox');
    var hint = document.getElementById('attDeliveredHint');
    var reason = document.getElementById('att_reason');

    function sync() {
        var failed = document.getElementById('att_failed').checked;
        failedBox.hidden = !failed;
        hint.hidden = failed;
        reason.required = failed;
    }
    document.querySelectorAll('input[name="outcome"]').forEach(function (r) { r.addEventListener('change', sync); });

    modal.addEventListener('show.bs.modal', function (ev) {
        var btn = ev.relatedTarget;
        if (!btn) { return; }
        form.action = form.getAttribute('data-base') + '/' + btn.getAttribute('data-shipment-id') + '/attempt';
        document.getElementById('attemptTracking').textContent = btn.getAttribute('data-tracking') || '';
        document.getElementById('att_delivered').checked = true;
        reason.value = '';
        sync();
    });
    sync();
});
</script>
