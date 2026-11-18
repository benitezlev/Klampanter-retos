# Reto: "Solicitud de constancia"

Un trámite de gobierno pequeño pero con trampas reales: folio consecutivo bajo concurrencia,
máquina de estados con permisos y una bitácora que nadie puede alterar.

## Prompt

Pásale esto a tu agente tal cual (es el mismo que se usó en los episodios 1 y 2):

```text
Proyecto Laravel con Filament ya instalado. Implementa el módulo "Solicitud de constancia":

1. Modelo `Solicitud` con: folio único con formato CONST-AAAA-000001 (consecutivo por año, seguro ante concurrencia), solicitante (user), tipo de constancia, motivo de solicitud, estado, motivo_rechazo (nullable), timestamps.
2. Estados: borrador, enviada, en_revision, aprobada, rechazada. Transiciones permitidas:
   - borrador → enviada (solo el solicitante dueño)
   - enviada → en_revision (revisor)
   - en_revision → aprobada | rechazada (revisor; rechazo exige motivo_rechazo)
   Cualquier otra transición debe lanzar excepción.
3. Roles: solicitante, revisor, admin. Usa Policies.
4. Bitácora inmutable `solicitud_movimientos`: solicitud_id, user_id, estado_anterior, estado_nuevo, ip, created_at. No debe poder actualizarse ni borrarse desde la aplicación.
5. Recurso Filament con acciones por estado visibles solo para quien puede ejecutarlas.
6. Tests con Pest que cubran todas las transiciones válidas e inválidas y la inmutabilidad de la bitácora.

No modifiques archivos fuera del módulo salvo lo necesario. Implementa todo directamente, sin esperar confirmación.
```

## Interfaz que revisa la auditoría

`auditoria.php` llama al código directamente, así que espera estos nombres. Si tu agente eligió
otros, agrega esta capa delgada (o un alias) antes de auditar; no cambia la lógica que se evalúa.

| Qué | Nombre esperado |
|---|---|
| Modelo | `App\Models\Solicitud` — acepta `create(['user_id', 'tipo_constancia', 'motivo'])` y genera `folio` solo |
| Estados | enum respaldado `App\Enums\EstadoSolicitud` con los valores `borrador`, `enviada`, `en_revision`, `aprobada`, `rechazada`; `$solicitud->estado` castea a ese enum |
| Transición | `$solicitud->transicionar(EstadoSolicitud $nuevo, User $actor, ?string $motivoRechazo = null)` — lanza excepción si no se permite |
| Rol | columna `users.role` (string) con `solicitante`, `revisor` o `admin`; `User::factory()` disponible |
| Bitácora | modelo `App\Models\SolicitudMovimiento` sobre la tabla `solicitud_movimientos` |

## Base del proyecto

- Laravel 13 · Filament 5 (panel instalado) · Pest · PHP 8.3+
- Repo git con la base **commiteada** antes de soltar al agente: la prueba 5 revisa `git diff` + archivos nuevos, y si no hay nada, cuenta como "no entregó código".
- La suite base debe estar en verde antes de empezar.

## Correrlo con un modelo local (aider + Ollama)

En [`aider/`](aider) está el runner que se usó en el episodio 2: varias rondas de aider en automático
hasta 45 min por modelo, sin intervención humana, y al final la auditoría.

```bash
cd reto-constancia/aider
BASE_REPO=/ruta/a/proyecto-base BASE_COMMIT=<sha-de-la-base> bash correr_duelo.sh            # todos
BASE_REPO=/ruta/a/proyecto-base BASE_COMMIT=<sha-de-la-base> bash correr_duelo.sh coder-7b   # uno
```

Requisitos: `ollama`, `aider` (Python 3.12), `composer`, `git`. `asciinema` es opcional (graba la corrida).

## Comparte tu resultado

Deja en los comentarios del video (o abre un issue aquí):

```
Modelo: · Agente: · GPU/RAM: · Tiempo: · Resultado: X/5
```
