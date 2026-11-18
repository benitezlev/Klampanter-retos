# KlamPanter · Retos

Retos y scripts de auditoría de los videos de [KlamPanter](https://www.youtube.com/@KlamPanter):
le doy a un agente de IA un trámite de gobierno real, y un script lo audita con pruebas que no
dependen de los tests que escribió el propio agente.

## Reto 1 · "Solicitud de constancia" (Laravel + Filament)

- 📄 Prompt, interfaz y cómo correrlo: [`reto-constancia/RETO.md`](reto-constancia/RETO.md)
- 🔍 Auditoría (5 pruebas): [`auditoria/auditoria.php`](auditoria/auditoria.php)
- 🤖 Runner para modelos locales con aider + Ollama: [`reto-constancia/aider/`](reto-constancia/aider)

### Las 5 pruebas

| # | Prueba | Pasa si… |
|---|---|---|
| 1 | El solicitante intenta aprobar su propia solicitud | se bloquea y sigue `en_revision` |
| 2 | El revisor rechaza sin motivo (`null` y `"   "`) | se bloquean las dos |
| 3 | Editar y borrar la bitácora (Eloquent, query builder y SQL directo) | 5/5 intentos bloqueados, registro intacto |
| 4 | 10 solicitudes creadas al mismo tiempo (10 procesos) | 10 folios `CONST-AAAA-000001`, sin duplicados ni huecos |
| 5 | Código limpio | todo compila, sin `dd()`/`dump()`, suite completa en verde, y sí entregó código |

```bash
php auditoria/auditoria.php /ruta/a/tu-proyecto
```

Usa una base SQLite temporal: no toca la base de tu proyecto. Requiere PHP 8.3+ y que el
proyecto tenga `vendor/` instalado.

### Resultados

| Episodio | Agente · modelo | Hardware | Tiempo | Auditoría |
|---|---|---|---|---|
| 1 | Claude Code · Opus 5.5 | nube | ~4 min | **5/5** — suite 85/85, 0 correcciones |
| 1 | Cline · Qwen 3.5 4.7B (aprobación manual) | 2× GTX 1050 Ti 4 GB | 45 min, no completó | **0/5** |
| 2 | aider · Qwen2.5-Coder 7B | 2× GTX 1050 Ti 4 GB | 45 min | **0/5** — no escribió archivos (bucle de repetición) |
| 2 | aider · Qwen2.5-Coder 14B | 2× GTX 1050 Ti 4 GB | 45 min | **0/5** — no escribió archivos (73% en CPU, bucle) |
| 2 | aider · Qwen 3.5 4.7B | 2× GTX 1050 Ti 4 GB | 45 min | **0/5** — 10 archivos, la app no arranca (tipo `string` vs `?string`) |
| 2 | aider · Qwen 3.5 9B | 2× GTX 1050 Ti 4 GB | 45 min | **0/5** — 6 archivos, no compila (lo cortó el tiempo mientras corregía) |

Ep. 2: [Probé 4 IAs locales con un trámite de gobierno: 0 de 5](https://youtu.be/sTCbk0vq_JY)

## Comparte tu resultado

Corre el reto con tu modelo y deja tu marcador en los comentarios del video o en un
[issue](../../issues):

```
Modelo: · Agente: · GPU/RAM: · Tiempo: · Resultado: X/5
```

## Licencia

MIT — por [KLAMD](https://klamd.com.mx).
