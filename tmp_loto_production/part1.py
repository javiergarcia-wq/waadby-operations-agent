SLIDES = [
    {
        "slide": 1, "target": 35, "pre": 2,
        "segments": [
            {"mode": "intro", "pause": 0.6, "text": "Bienvenidos a esta formación de Elis sobre Securing, o Asegurar, y LOTO. Vamos a trabajar una idea muy práctica: antes de tocar una máquina, tenemos que saber si está realmente en una condición segura."},
            {"mode": "close", "pause": 0.8, "text": "Durante la formación iremos viendo cuándo basta con Asegurar y cuándo es obligatorio aplicar LOTO. Y, sobre todo, cómo tomar esa decisión sin improvisar."}
        ]
    },
    {
        "slide": 2, "target": 55, "pre": 3,
        "segments": [
            {"mode": "intro", "pause": 0.5, "text": "Antes de entrar en materia, una breve presentación del formador."},
            {"mode": "explain", "pause": 0.7, "text": "La sesión está dirigida por José María Gómez, Director del Departamento Industrial de Mantenimiento y de Ingeniería de Métodos de Elis España."},
            {"mode": "close", "pause": 0.8, "text": "La intención no es hacer una formación teórica sin más. Vamos a conectar el estándar con situaciones que pueden aparecer de verdad en mantenimiento y producción."}
        ]
    },
    {
        "slide": 3, "target": 110, "pre": 5,
        "segments": [
            {"mode": "intro", "pause": 0.7, "text": "Vamos con la regla que da sentido a todo el módulo: trabajar con el equipo de forma segura."},
            {"mode": "question", "pause": 1.0, "text": "¿Qué significa esto en la práctica? Que no intervenimos en una máquina que está funcionando. Y que, cuando trabajamos sobre un equipo, aplicamos el procedimiento de bloqueo o etiquetado que corresponda."},
            {"mode": "key", "pause": 1.0, "text": "Ojo con una confusión muy común: que una máquina esté parada no significa, por sí solo, que sea segura."},
            {"mode": "explain", "pause": 0.8, "text": "Puede quedar energía eléctrica. Puede haber presión neumática. Puede quedar vapor, movimiento mecánico, producto químico o incluso energía acumulada. Por eso no nos fiamos solo de lo que vemos o de lo que oímos."},
            {"mode": "close", "pause": 1.0, "text": "A lo largo del curso vamos a aprender a reconocer esas situaciones y a elegir entre Asegurar y LOTO con un criterio claro."}
        ]
    },
    {
        "slide": 4, "target": 90, "pre": 4,
        "segments": [
            {"mode": "intro", "pause": 0.6, "text": "Antes de seguir, acordamos unas reglas sencillas para aprovechar bien la sesión."},
            {"mode": "enum1", "pause": 0.35, "text": "Primero: escribe tu nombre en la etiqueta o caballete."},
            {"mode": "enum2", "pause": 0.35, "text": "Segundo: apaga o silencia el móvil y el ordenador para evitar distracciones."},
            {"mode": "enum3", "pause": 0.45, "text": "Tercero: respeto. Escuchamos y dejamos que los demás terminen sus ideas."},
            {"mode": "explain", "pause": 0.8, "text": "Y durante la sesión, si algo no se entiende, dilo. Si has vivido una situación parecida, compártela. La experiencia del grupo también forma parte del aprendizaje."},
            {"mode": "close", "pause": 0.8, "text": "Y una cosa importante: aquí no hay preguntas tontas. En seguridad, preguntar a tiempo es mucho mejor que asumir."}
        ]
    },
    {
        "slide": 5, "target": 105, "pre": 5,
        "segments": [
            {"mode": "intro", "pause": 0.7, "text": "Ahora nos situamos como grupo. La formación no parte del mismo punto para todo el mundo, y por eso conviene saber quién está delante."},
            {"mode": "explain", "pause": 0.8, "text": "Revisa los datos que aparecen en pantalla: nombre, centro, función y una breve descripción del equipo con el que trabajas habitualmente."},
            {"mode": "question", "pause": 1.2, "text": "También hay dos preguntas que nos interesan especialmente. ¿Tienes autorización o competencias eléctricas? ¿Y habías leído ya el estándar de Securing, Asegurar y LOTO?"},
            {"mode": "explain", "pause": 0.8, "text": "No se trata de examinar a nadie en este momento. Se trata de entender el punto de partida y comparar tus expectativas con los objetivos de la formación."},
            {"mode": "close", "pause": 1.0, "text": "Tómate unos segundos para revisar esta diapositiva y pensar qué te gustaría tener claro al terminar."}
        ]
    },
    {
        "slide": 6, "target": 75, "pre": 4,
        "segments": [
            {"mode": "intro", "pause": 0.5, "text": "Este es el recorrido que vamos a seguir."},
            {"mode": "enum1", "pause": 0.35, "text": "Empezaremos por los objetivos."},
            {"mode": "enum2", "pause": 0.35, "text": "Después veremos el contexto y por qué este tema sigue siendo crítico."},
            {"mode": "enum3", "pause": 0.35, "text": "Entraremos en Securing, o Asegurar."},
            {"mode": "enum4", "pause": 0.35, "text": "Luego veremos LOTO con más detalle."},
            {"mode": "enum5", "pause": 0.55, "text": "Y cerraremos con una síntesis, un diagrama de decisión y un taller sobre el terreno."},
            {"mode": "close", "pause": 0.8, "text": "La idea es avanzar de lo sencillo a lo operativo: saber qué procedimiento toca y ser capaz de aplicarlo."}
        ]
    },
    {
        "slide": 7, "target": 15, "pre": 2,
        "segments": [
            {"mode": "intro", "pause": 0.8, "text": "Empezamos por el primer bloque: los objetivos de la formación."}
        ]
    },
    {
        "slide": 8, "target": 160, "pre": 5,
        "segments": [
            {"mode": "intro", "pause": 0.6, "text": "¿Qué deberíamos ser capaces de hacer al terminar? Hay tres objetivos principales."},
            {"mode": "enum1", "pause": 0.45, "text": "Uno: conocer las reglas principales de Securing, Asegurar y LOTO aplicables a los equipos de Elis."},
            {"mode": "enum2", "pause": 0.45, "text": "Dos: saber cómo se configura y cómo se lleva a la práctica cada procedimiento."},
            {"mode": "enum3", "pause": 0.8, "text": "Y tres: mejorar la evaluación de riesgos antes de una intervención de mantenimiento."},
            {"mode": "key", "pause": 0.9, "text": "Este último punto es clave. No queremos que la persona memorice cinco pasos y ya está. Queremos que entienda por qué los aplica y qué riesgo está controlando."},
            {"mode": "explain", "pause": 0.8, "text": "La formación afecta a cualquier persona de producción o mantenimiento que pueda encontrarse con operaciones de Aseguramiento o LOTO. Eso incluye también situaciones en las que intervienen contratistas."},
            {"mode": "explain", "pause": 0.8, "text": "Y el aprendizaje tiene dos partes: una parte teórica, para entender el criterio; y una parte práctica, para llevarlo a la máquina y comprobar que sabemos reconocer accesos, energías, dispositivos de aislamiento y condiciones de intervención."},
            {"mode": "close", "pause": 1.0, "text": "Mientras lees la diapositiva, quédate con esta idea: conocer el procedimiento es necesario, pero saber elegirlo correctamente es igual de importante."}
        ]
    },
    {
        "slide": 9, "target": 15, "pre": 2,
        "segments": [
            {"mode": "intro", "pause": 0.8, "text": "Pasamos al contexto. Vamos a ver por qué Asegurar y LOTO no son un formalismo, sino una medida de prevención muy concreta."}
        ]
    },
    {
        "slide": 10, "target": 160, "pre": 6,
        "segments": [
            {"mode": "intro", "pause": 0.7, "text": "Empezamos con un dato de contexto de Francia en 2023 relacionado con el contacto con maquinaria."},
            {"mode": "explain", "pause": 0.8, "text": "La diapositiva recoge quince accidentes con baja vinculados al contacto con máquinas. Y el impacto no se mide solo por el número de accidentes: hablamos de más de cuatrocientos setenta y cinco días perdidos."},
            {"mode": "key", "pause": 0.9, "text": "Eso supone alrededor de treinta y dos días perdidos por accidente. Es una cifra que nos habla de gravedad, no solo de frecuencia."},
            {"mode": "explain", "pause": 0.8, "text": "En la pantalla también aparece el contexto general de días perdidos por accidentes con baja. No hace falta memorizar cada número. Lo que tenemos que entender es la relación: cuando una intervención sobre maquinaria sale mal, las consecuencias pueden ser importantes y prolongadas."},
            {"mode": "question", "pause": 1.0, "text": "Así que la pregunta no es solamente cuántos incidentes tenemos. La pregunta es: ¿cuántos de esos incidentes podrían haberse evitado con un procedimiento de seguridad bien aplicado?"},
            {"mode": "close", "pause": 1.2, "text": "Tómate un momento para leer los indicadores. En la siguiente diapositiva veremos la evolución de varios años y esa relación se ve todavía mejor."}
        ]
    },
    {
        "slide": 11, "target": 240, "pre": 8,
        "segments": [
            {"mode": "intro", "pause": 0.7, "text": "Aquí tenemos una tabla más densa. No vamos a leerla como si fuera una hoja de cálculo. Vamos a sacar las conclusiones importantes."},
            {"mode": "explain", "pause": 0.8, "text": "La fecha estándar de implantación que aparece es enero de 2023. La tabla compara 2022, 2023, 2024 y 2025. El total de accidentes con baja y sin baja se mueve en cifras elevadas, y en 2025 la referencia se limita a los accidentes con baja."},
            {"mode": "explain", "pause": 0.8, "text": "Si miramos los accidentes por contacto con maquinaria, contacto químico o descarga eléctrica, aparecen ciento ochenta y cuatro en 2022, ciento setenta y seis en 2023, ciento treinta y tres en 2024 y doscientos ocho en 2025. En porcentaje, estamos aproximadamente entre el diez y el doce por ciento del total."},
            {"mode": "key", "pause": 1.0, "text": "Ahora viene el dato que nos interesa especialmente: una parte significativa de esos accidentes se considera evitable mediante Asegurar o LOTO."},
            {"mode": "explain", "pause": 0.8, "text": "Son sesenta y seis casos en 2022, cincuenta y nueve en 2023, cincuenta y nueve en 2024 y ochenta en 2025. Es decir, aproximadamente entre un tercio y algo más del cuarenta por ciento de los accidentes de esa categoría."},
            {"mode": "serious", "pause": 0.9, "text": "Y dentro de esos casos evitables aparecen accidentes de alta severidad: fatalidad, fractura, amputación, quemadura térmica, quemadura química o descarga eléctrica."},
            {"mode": "key", "pause": 1.0, "text": "Por eso este estándar no se plantea como una recomendación cómoda. Se plantea para evitar sucesos que pueden tener consecuencias muy graves."},
            {"mode": "close", "pause": 1.0, "text": "Deja unos segundos para revisar la tabla por tu cuenta. No memorices todos los porcentajes; fíjate en la tendencia y en el peso de los casos que podían haberse evitado."}
        ]
    },
    {
        "slide": 12, "target": 15, "pre": 2,
        "segments": [
            {"mode": "intro", "pause": 0.8, "text": "Entramos ahora en Securing, o Asegurar. Empezamos por entender qué es y, sobre todo, para qué tipo de tareas está pensado."}
        ]
    },
    {
        "slide": 13, "target": 210, "pre": 7,
        "segments": [
            {"mode": "intro", "pause": 0.7, "text": "Asegurar se utiliza para realizar una tarea recurrente en una máquina, garantizando que los movimientos peligrosos se han detenido."},
            {"mode": "question", "pause": 0.8, "text": "La palabra importante aquí es recurrente. ¿Qué entendemos por una tarea recurrente?"},
            {"mode": "explain", "pause": 0.8, "text": "Hablamos de tareas previstas en el uso normal de la máquina, tareas que pueden aparecer de forma habitual y que no requieren desmontar un dispositivo o un componente."},
            {"mode": "key", "pause": 0.9, "text": "Además, esa tarea debe estar definida localmente por el responsable del centro Elis o por su responsable de seguridad. No decidimos sobre la marcha que algo es recurrente porque nos conviene."},
            {"mode": "explain", "pause": 0.8, "text": "El principio puede aplicarse desde producción, ingeniería o por contratistas, siempre dentro de las condiciones definidas."},
            {"mode": "explain", "pause": 0.8, "text": "La diapositiva da varios ejemplos: análisis de fallos sin intervención eléctrica ni mecánica, limpieza de sensores, atascos de ropa, ajustes de sensores o presostatos, observaciones visuales de mantenimiento preventivo y diagnósticos realizados por contratistas."},
            {"mode": "key", "pause": 0.9, "text": "Fíjate en lo que tienen en común: estamos observando, limpiando, ajustando o resolviendo una situación sin desmontar componentes y sin intervenir sobre una fuente de energía."},
            {"mode": "close", "pause": 1.2, "text": "Si la tarea deja de cumplir esas condiciones, ya no podemos asumir que Asegurar es suficiente. Ahí empieza la frontera con LOTO, que veremos enseguida."}
        ]
    },
    {
        "slide": 14, "target": 180, "pre": 6,
        "segments": [
            {"mode": "intro", "pause": 0.7, "text": "¿Cómo se aplica Asegurar? El procedimiento se resume en tres pasos. Tres pasos sencillos, pero tienen que hacerse en orden."},
            {"mode": "enum1", "pause": 0.8, "text": "Primer paso: detener la máquina desde el panel de control."},
            {"mode": "explain", "pause": 0.6, "text": "No entramos, no tocamos y no empezamos a intervenir antes de que la máquina se haya detenido."},
            {"mode": "enum2", "pause": 0.8, "text": "Segundo paso: abrir una puerta de acceso, una rejilla o una carcasa equipada con un bloqueo o interbloqueo de seguridad, de forma que aprovechemos el propio sistema de seguridad de la máquina."},
            {"mode": "key", "pause": 0.8, "text": "Este paso no es decorativo. Es el que evita que la máquina pueda funcionar normalmente mientras estamos dentro de la zona protegida."},
            {"mode": "enum3", "pause": 0.8, "text": "Tercer paso: colocar un cartel de información en el panel de control."},
            {"mode": "explain", "pause": 0.8, "text": "Ese cartel hace visible para los demás que hay una intervención en curso. La seguridad no depende solo de quien está dentro; también depende de que los demás entiendan el estado del equipo."},
            {"mode": "close", "pause": 1.0, "text": "Repásalos una vez más: parar, abrir el acceso protegido y señalizar. En la siguiente diapositiva veremos qué ocurre cuando la persona no es visible o cuando la máquina no dispone de ese acceso interbloqueado."}
        ]
    },
    {
        "slide": 15, "target": 210, "pre": 7,
        "segments": [
            {"mode": "intro", "pause": 0.7, "text": "Este segundo paso de Asegurar necesita algunas precisiones importantes."},
            {"mode": "key", "pause": 0.8, "text": "Si la persona que interviene dentro de la máquina no es visible desde el punto de acceso, se coloca un candado en la puerta y la persona que está interviniendo se queda con la llave."},
            {"mode": "question", "pause": 0.8, "text": "¿Y si la máquina no tiene una puerta de acceso equipada con un bloqueo de seguridad?"},
            {"mode": "explain", "pause": 0.9, "text": "En ese caso, la diapositiva indica apagar el aislador eléctrico, llevarlo a posición cero y hacer una prueba de funcionamiento para comprobar que el aislamiento es correcto."},
            {"mode": "serious", "pause": 0.9, "text": "También hay que prestar atención a las fuentes calientes. Una tubería de vapor o una parte de la instalación puede seguir caliente aunque se haya activado un dispositivo de seguridad. Por eso debe existir protección térmica cuando haya riesgo de quemadura por contacto."},
            {"mode": "explain", "pause": 0.8, "text": "Y, siempre que sea posible, la misma persona que realizó el Aseguramiento es quien retira el cartel, cierra la puerta y permite el reinicio de la máquina."},
            {"mode": "key", "pause": 0.9, "text": "En cuanto a trazabilidad, el estándar indica que Asegurar no requiere un documento específico."},
            {"mode": "close", "pause": 1.2, "text": "Quédate con el criterio completo: Asegurar sirve para tareas recurrentes definidas, con la máquina detenida, el acceso protegido y la intervención señalizada. Cuando necesitamos intervenir sobre energías, fluidos, componentes o desmontajes, damos un paso más: LOTO."}
        ]
    },
]
