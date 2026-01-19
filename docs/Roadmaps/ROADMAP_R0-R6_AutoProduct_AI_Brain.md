
# 🧠 ROADMAP R0–R6 — AutoProduct AI Brain
**Versión final (Baseline A1.5.29d)**

## Estado
- **Aprobado:** ✅  
- **Baseline:** A1.5.29d (Mini-Roadmap Kodee-like CERRADO)  
- **Ámbito:** Brain únicamente  
- **Fuera de alcance:** Agent, Core, SaaS  

---

## 🎯 Objetivo general

Evolucionar el Brain desde un sistema sólido, seguro y determinista hacia uno que además:

- comprenda lenguaje libre de forma natural  
- razone antes de rutear  
- pida aclaraciones como ChatGPT  
- mantenga seguridad, auditabilidad y control  

Este roadmap **no busca creatividad**, busca **mejor juicio**.

---

## 🔒 Baseline no negociable

El estado **A1.5.29d** queda **CERRADO** y no se reabre:

- ChitChatFlow real  
- Off-domain guard  
- Clarificación honesta  
- Context hints seguros  
- Followup correcto  
- NOOP real  
- Pending consistente  
- Regla de oro: *el modelo redacta, los flows deciden*  

---

## R0 — Congelación de invariantes

### Objetivo
Permitir evolución sin romper comportamiento externo.

### Acciones
- Documentar invariantes:
  - Queries no mutan estado  
  - Actions siempre generan pending  
  - Pending 100% server-side  
  - Full context nunca al modelo  
  - `meta.trace_id` obligatorio  
- Soft-asserts (warnings)
- Documentar contrato Brain → Agent

### Cierre
- Invariantes documentadas  
- Warnings activos  
- Sin cambios funcionales  

---

## R1 — PlanningService

### Objetivo
Separar comprensión de ruteo.

### Acciones
- Nuevo `PlanningService`
- Genera `Plan` con:
  - intent_kind  
  - confidence  
  - needs_clarification  
  - target  
  - field  
  - evidence[]  
- El pipeline rutea basado en el Plan
- Registro del Plan en trazas

### Impacto
- Menos respuestas erráticas  
- Base para mejor UX  

### Cierre
- Todo request genera Plan  
- Plan visible en trace  
- Output sin cambios  

---

## R2 — Clarification UX no-bot

### Objetivo
Aclaraciones humanas y útiles.

### Acciones
- `ClarificationBuilder`
- 1 pregunta clara
- Opciones sugeridas
- Ejemplo corto

### Impacto
- Menos frustración  
- Sensación ChatGPT-like  

### Cierre
- No hay aclaraciones genéricas  

---

## R3 — Desacoplar ModelFlow

### Objetivo
Evitar “god flow”.

### Acciones
- Separar:
  - OffDomainService  
  - SemanticFallbackService  
  - LLMChatService  
  - UXGuardrailService  

### Cierre
- Mismo comportamiento externo  
- Código más mantenible  

---

## R4 — Context Lite por evidencia

### Objetivo
Mejor contexto sin Full.

### Acciones
- `ContextSelector(plan)`
- Incluye:
  - último target  
  - última acción/query  
  - pending actual  

### Cierre
- Contexto explícito  
- Tokens controlados  

---

## R5 — Dataset y regresión

### Objetivo
Mejorar sin miedo.

### Acciones
- Telemetría → JSONL
- Harness valida:
  - route  
  - invariantes  
  - NOOP  

### Cierre
- Dataset vivo  
- Bugs no regresan  

---

## R6 — Comprensión libre gated

### Objetivo
Entender frases humanas con control.

### Acciones
- Expandir SemanticInterpreter
- Verbos naturales
- Gating estricto

### Cierre
- Más frases entendidas  
- Sin alucinaciones  

---

## 📊 Evaluación final

- Brain actual: **~7/10**
- Brain con R0–R6: **~9/10 realista**

Objetivo: **máxima confiabilidad**, no creatividad sin control.
