# AutoProduct AI — Brain

Este repositorio contiene el **Brain** del sistema AutoProduct AI.

El Brain es responsable de:
- comprender lenguaje natural,
- razonar intención,
- decidir rutas (query / action / chitchat),
- preparar acciones (pending),
sin ejecutar cambios directos en WooCommerce.

---

## 🧠 Arquitectura del sistema

AutoProduct AI está dividido en capas estrictas:

- **Brain** → entiende y decide (este repositorio)
- **Agent** → ejecuta acciones en WooCommerce (fuera de este repo)
- **Core** → infraestructura y conexión SaaS (fuera de este repo)
- **SaaS** → consumo de OpenAI / modelos (fuera de este repo)

**Regla de oro:**  
> El modelo redacta, los flows deciden.

---

## 📚 Fuente de verdad (OBLIGATORIO)

Antes de modificar código, leer en este orden:

1. `/docs/roadmap/ROADMAP_R0-R6_AutoProduct_AI_Brain.md`  
   → Roadmap oficial activo (R0–R6)

2. `/docs/INVARIANTS.md`  
   → Reglas duras que no se pueden romper

3. `/docs/architecture/MENSAJE_0.md` (si existe)  
   → Contexto maestro del proyecto

⚠️ Los PDFs en `/docs/_pdf/` son **respaldo histórico**.  
Si hay contradicción, **manda siempre el `.md`**.

---

## 🎯 Principios no negociables

- Brain **no ejecuta acciones**
- Agent **no razona**
- Queries son **read-only**
- Actions siempre generan **pending**
- Pending es **100% server-side**
- Full context **nunca** se envía al modelo
- Sin inferencias implícitas
- Si hay duda → **clarificación honesta**

---

## 🛠️ Método de trabajo esperado

- Cambios pequeños y revisables
- Un commit / PR por fase del roadmap
- Refactor interno permitido **solo** si no cambia comportamiento externo
- Evitar “patch sobre patch”

---

## 🧪 Testing / QA

El Brain debe mantenerse:
- determinista
- auditable
- seguro

Cualquier mejora de comprensión debe:
- pasar por gating
- respetar invariantes
- no generar alucinaciones

---

## 📌 Estado actual

- Baseline: **A1.5.29d**
- Mini-roadmap “Kodee-like”: **CERRADO**
- Roadmap activo: **R0–R6**

---

