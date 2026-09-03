# Changelog

## 1.2.2 - 2026-09-03

- Vincula el enrollment firmado a la identidad local opcional de la instalación consumidora y falla cerrado cuando el fichero se copia a otro servidor.
- Expone la identidad local vinculada en el estado seguro del Agent.

## 1.2.1 - 2026-09-03

- Excluye el fichero de metadatos `.git` usado por Git worktrees al construir releases, manteniendo el mismo bloqueo estricto para rutas `.git/` del paquete.

## 1.2.0 - 2026-09-03

- Primera distribución canónica independiente extraída con historia desde WAADBY ACCESS.
- Runtime consumidor configurable y validado contra `OperationsRuntime`.
- Estado seguro del Agent con versión, enrollment y actividad reciente.
- Enrollment declara capacidades y conserva application/environment binding.
