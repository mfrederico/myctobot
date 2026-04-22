<?php
/**
 * Shared mobile-first styling for the communications hub.
 *
 * Goal: on phones, feel like a messaging app, not a card inside a card.
 * Desktop (lg+) keeps the nicer split-pane card layout.
 *
 * Scoped via `.comms-hub` so it only affects communications pages.
 */
?>
<style>
/* ────── Base (all breakpoints) ────── */

.comms-hub .comms-msg-bubble {
    padding: 0.6rem 0.75rem;
    border-radius: 14px;
    word-break: break-word;
    font-size: 0.875rem;
    line-height: 1.45;
    box-shadow: 0 1px 1px rgba(0,0,0,0.04);
}
.comms-hub .comms-msg-bubble.out {
    background: #e7f1ff;
    border-bottom-right-radius: 4px;
}
.comms-hub .comms-msg-bubble.in {
    background: #f1f3f5;
    border-bottom-left-radius: 4px;
}
.comms-hub .comms-msg-meta {
    font-size: 0.72rem;
    color: #6c757d;
    margin-bottom: 0.2rem;
}

.comms-hub .comms-thread-row.unread .comms-thread-subject { font-weight: 700; }
.comms-hub .comms-thread-row .comms-unread-dot {
    display: inline-block;
    width: 8px; height: 8px;
    border-radius: 50%;
    background: #0d6efd;
    margin-right: 0.5rem;
    flex-shrink: 0;
    visibility: hidden;
}
.comms-hub .comms-thread-row.unread .comms-unread-dot { visibility: visible; }

/* Sticky action bar — primary actions stay reachable while the feed scrolls. */
.comms-hub .comms-actionbar {
    position: sticky;
    top: 0;
    z-index: 5;
    min-height: 52px;
}
.comms-hub .comms-messages-body > :first-child { margin-top: 0.25rem; }
.comms-hub .comms-msg-bubble-wrap             { margin-top: 10px; }

.comms-hub #comms-reply-panel { margin-top: 0.5rem; }
.comms-hub #comms-reply-panel .card { position: relative; z-index: 2; }

.comms-hub .comms-thread-list { overflow-x: hidden; }
.comms-hub .comms-thread-row .comms-thread-subject {
    min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.comms-hub .comms-thread-row           { display: block; max-width: 100%; overflow: hidden; }
.comms-hub .comms-thread-row > .d-flex { max-width: 100%; }
.comms-hub .comms-thread-row .min-w-0  { flex: 1 1 0; min-width: 0; max-width: 100%; }
.comms-hub .comms-thread-row .min-w-0 .text-truncate { max-width: 100%; display: block; }

/* ────── Mobile: edge-to-edge ────── */
@media (max-width: 767.98px) {
    /* The CRM layout wraps content in .crm-content with no horizontal padding;
       but list-group / card-header add their own. Negative margins let the
       feed go wall-to-wall without introducing horizontal scroll. */
    .comms-hub { margin-left: 0; margin-right: 0; }
    .comms-hub .row { margin-left: 0; margin-right: 0; --bs-gutter-x: 0; }
    .comms-hub .row > [class*="col-"] { padding-left: 0; padding-right: 0; }

    .comms-hub .card {
        border-radius: 0 !important;
        box-shadow: none !important;
        border-left: 0; border-right: 0;
        margin-bottom: 0 !important;
    }

    .comms-hub .comms-messages-body,
    .comms-hub .comms-thread-list {
        max-height: none !important;
        overflow-y: visible;
    }

    .comms-hub .comms-msg-bubble-wrap { max-width: 92%; }

    .comms-hub .comms-contact-group {
        position: sticky; top: 0; z-index: 2;
        background: #f8f9fa;
        padding: 0.4rem 1rem !important;
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }
    .comms-hub .comms-thread-row { padding: 0.85rem 1rem; }
}

/* ────── Desktop ────── */
@media (min-width: 992px) {
    .comms-hub .comms-msg-bubble-wrap { max-width: 80%; }
}
</style>
