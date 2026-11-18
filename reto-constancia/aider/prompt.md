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

No modifiques archivos fuera del módulo salvo lo necesario. Implementa todo directamente, sin esperar confirmación. Crea tú mismo cada archivo nuevo escribiéndolo completo con su ruta (por ejemplo app/Models/Solicitud.php); no pidas que te agreguen archivos.
