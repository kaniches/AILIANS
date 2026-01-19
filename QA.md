# AutoProduct AI — Brain QA Checklist (F6)

> Regla: si **1 solo** ítem falla → **NO** se entrega ZIP.

## 0) Pre-flight (antes de testear)
- [ ] WP Admin logueado
- [ ] Plugin Brain activo (solo Brain; Core/Agent intactos)
- [ ] Consola del navegador sin errores rojos al cargar la página del chat
- [ ] `Debug Lite` muestra `store_state` en cada respuesta

## 1) Invariantes (siempre)
- [ ] **store_state** siempre presente en la respuesta
- [ ] `meta.trace_id` siempre presente en Debug Lite
- [ ] Context **Full nunca** se envía al modelo (Debug Lite: `context_full_chars = 0`)
- [ ] UI muestra botones **solo si** `store_state.pending_action != null` (fuente de verdad server-side)

## 2) Queries A1–A8 (read-only, sin botones)
- [ ] A1 sin precio → sin botones, no cambia `pending_action`
- [ ] A2 sin descripción → sin botones, no cambia `pending_action`
- [ ] A3 sin SKU → sin botones, no cambia `pending_action`
- [ ] A4 sin categoría → sin botones, no cambia `pending_action`
- [ ] A5 sin imagen destacada → sin botones, no cambia `pending_action`
- [ ] A6 incompletos → sin botones, no cambia `pending_action`
- [ ] A7 stock → sin botones, no cambia `pending_action`
- [ ] A8 salud del catálogo → sin botones, soporta follow-up `full` y muestra top 5

## 3) Pending (confirmar / cancelar / corregir)
- [ ] Crear pending: “cambiá el precio del último a 10000” → aparece **ACCIÓN PROPUESTA** + botones
- [ ] Corregir pending: “mejor a 1200” → se actualiza la misma acción propuesta
- [ ] Confirmar: botón confirmar → oculta botones + “✅ Ejecutada” (pending vuelve a null)
- [ ] Cancelar: botón cancelar o texto “cancelar” → oculta botones + “✅ Cancelada” (pending vuelve a null)
- [ ] Si hay pending y usuario intenta otra acción distinta → el Brain pregunta qué hacer (seguir / cancelar)
- [ ] Si hay pending, las **queries** siguen funcionando (no se bloquean)

## 4) Target selection (selector de producto)
- [ ] Si hay selección pendiente (target selection), **smalltalk** (“hola”, “buenas”) se bloquea con copy claro
- [ ] Si hay selección pendiente, una **query** no debe “hijackear” esa selección

## 5) Botones internos (herramientas)
- [ ] Botón **QA** → `ok: true`
- [ ] Botón **REG** → `failures: []`
- [ ] (Opcional) Health: solo admin + nonce (ver `docs/OBSERVABILITY.md`)

## 6) Suite canónica F6.7 (manual, repetible)
Ver `docs/REGRESSION_HARNESS.md` (scripts paso a paso + expected).
