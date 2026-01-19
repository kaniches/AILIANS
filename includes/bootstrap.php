<?php
/**
 * AutoProduct AI Brain bootstrap.
 *
 * @FLOW Bootstrap
 * @INVARIANT This file must not contain business logic. It only centralizes requires.
 *            Order is intentionally stable to avoid subtle load regressions.
 *
 * Centralizes includes to keep the plugin entry file small and to make future
 * refactors (and diff reviews) easier.
 *
 * IMPORTANT: Order matters in a few places (e.g. patterns before flows).
 * This file intentionally preserves the historical load order.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// -----------------------------------------------------------------------------
// Core
// -----------------------------------------------------------------------------
require_once APAI_BRAIN_PATH . 'includes/class-apai-brain.php';
require_once APAI_BRAIN_PATH . 'includes/class-apai-brain-rest.php';
require_once APAI_BRAIN_PATH . 'includes/class-apai-brain-kernel.php';
require_once APAI_BRAIN_PATH . 'includes/class-apai-brain-pipeline.php';

// -----------------------------------------------------------------------------
// Utilities / services
// -----------------------------------------------------------------------------
require_once APAI_BRAIN_PATH . 'includes/services/class-apai-brain-telemetry.php';
require_once APAI_BRAIN_PATH . 'includes/services/class-apai-brain-health.php';
require_once APAI_BRAIN_PATH . 'includes/services/class-apai-brain-qa-harness.php';
require_once APAI_BRAIN_PATH . 'includes/services/class-apai-brain-regression-harness.php';
require_once APAI_BRAIN_PATH . 'includes/services/class-apai-brain-trace.php';
require_once APAI_BRAIN_PATH . 'includes/services/class-apai-brain-memory-store.php';
require_once APAI_BRAIN_PATH . 'includes/services/class-apai-brain-noop.php';
require_once APAI_BRAIN_PATH . 'includes/services/class-apai-brain-normalizer.php';
require_once APAI_BRAIN_PATH . 'includes/services/class-apai-brain-nlg.php';
require_once APAI_BRAIN_PATH . 'includes/services/class-apai-brain-context-lite.php';
require_once APAI_BRAIN_PATH . 'includes/services/class-apai-brain-offdomain-detector.php';
require_once APAI_BRAIN_PATH . 'includes/services/class-apai-brain-chitchat-redactor.php';
require_once APAI_BRAIN_PATH . 'includes/services/class-apai-brain-context-full.php';
require_once APAI_BRAIN_PATH . 'includes/services/class-apai-brain-rest-observability.php';
require_once APAI_BRAIN_PATH . 'includes/services/class-apai-brain-response-builder.php';
require_once APAI_BRAIN_PATH . 'includes/services/class-apai-brain-intent-validator.php';
require_once APAI_BRAIN_PATH . 'includes/services/class-apai-brain-semantic-interpreter.php';
require_once APAI_BRAIN_PATH . 'includes/services/class-apai-brain-semantic-rewriter.php';
require_once APAI_BRAIN_PATH . 'includes/services/class-apai-brain-action-preparer.php';
require_once APAI_BRAIN_PATH . 'includes/services/class-apai-brain-semantic-dispatch.php';
require_once APAI_BRAIN_PATH . 'includes/services/class-apai-brain-pending-ui.php';
require_once APAI_BRAIN_PATH . 'includes/services/class-apai-brain-product-search.php';
// NOTE: Repository & presenter live outside /services.
require_once APAI_BRAIN_PATH . 'includes/queries/class-apai-catalog-repository.php';
require_once APAI_BRAIN_PATH . 'includes/presenters/class-apai-query-presenter.php';

// Centralized regex patterns
// (Moved to /includes/config to keep it clearly in the "configuration" layer.)
require_once APAI_BRAIN_PATH . 'includes/config/class-apai-patterns.php';

// -----------------------------------------------------------------------------
// Queries (A1–A8)
// -----------------------------------------------------------------------------
require_once APAI_BRAIN_PATH . 'includes/queries/class-apai-query-registry.php';
require_once APAI_BRAIN_PATH . 'includes/queries/handlers/class-apai-query-h-a1.php';
require_once APAI_BRAIN_PATH . 'includes/queries/handlers/class-apai-query-h-a2.php';
require_once APAI_BRAIN_PATH . 'includes/queries/handlers/class-apai-query-h-a3.php';
require_once APAI_BRAIN_PATH . 'includes/queries/handlers/class-apai-query-h-a4.php';
require_once APAI_BRAIN_PATH . 'includes/queries/handlers/class-apai-query-h-a5.php';
require_once APAI_BRAIN_PATH . 'includes/queries/handlers/class-apai-query-h-a6.php';
require_once APAI_BRAIN_PATH . 'includes/queries/handlers/class-apai-query-h-a7.php';
require_once APAI_BRAIN_PATH . 'includes/queries/handlers/class-apai-query-h-a8.php';

// -----------------------------------------------------------------------------
// Flows
// -----------------------------------------------------------------------------
require_once APAI_BRAIN_PATH . 'includes/flows/class-apai-brain-deterministic-flow.php';
require_once APAI_BRAIN_PATH . 'includes/flows/class-apai-brain-info-query-flow.php';
require_once APAI_BRAIN_PATH . 'includes/flows/class-apai-brain-pending-flow.php';
require_once APAI_BRAIN_PATH . 'includes/flows/class-apai-brain-followup-flow.php';
require_once APAI_BRAIN_PATH . 'includes/flows/class-apai-brain-target-correction-flow.php';
require_once APAI_BRAIN_PATH . 'includes/flows/class-apai-brain-intent-parse-flow.php';
require_once APAI_BRAIN_PATH . 'includes/flows/class-apai-brain-chitchat-flow.php';
require_once APAI_BRAIN_PATH . 'includes/flows/class-apai-brain-targeted-update-flow.php';
require_once APAI_BRAIN_PATH . 'includes/flows/class-apai-brain-query-flow.php';
require_once APAI_BRAIN_PATH . 'includes/flows/class-apai-brain-model-flow.php';

// -----------------------------------------------------------------------------
// Admin
// -----------------------------------------------------------------------------
require_once APAI_BRAIN_PATH . 'includes/class-apai-brain-admin.php';
