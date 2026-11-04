/* Portal ARB-A: filtro de "Mis archivos", selección y confirmación del panel. */
(() => {
  const buscar = document.getElementById('buscar');
  const vigencia = document.getElementById('vigencia');
  const filas = Array.from(document.querySelectorAll('#filas tr'));
  const vacio = document.getElementById('sin-resultados');

  if (filas.length > 0) {
    const aplicar = () => {
      const q = (buscar ? buscar.value : '').trim().toLowerCase();
      const modo = vigencia ? vigencia.value : 'todos';
      let visibles = 0;

      filas.forEach((tr) => {
        const nombre = tr.dataset.nombre || '';
        const dias = Number(tr.dataset.dias || 0);
        let mostrar = q === '' || nombre.includes(q);

        if (mostrar && modo === 'pronto') {
          mostrar = dias <= 7;
        }
        if (mostrar && modo === 'vigente') {
          mostrar = dias > 7;
        }

        tr.hidden = !mostrar;
        if (mostrar) {
          visibles += 1;
        }
      });

      if (vacio) {
        vacio.hidden = visibles !== 0;
      }
    };

    if (buscar) {
      buscar.addEventListener('input', aplicar);
    }
    if (vigencia) {
      vigencia.addEventListener('change', aplicar);
    }
  }

  // El maestro de la purga refleja solo las filas visibles.
  const sincronizarSeleccion = () => {
    const master = document.getElementById('seleccionar-todos');

    if (!master) {
      return;
    }

    const visibles = Array.from(document.querySelectorAll('input[name="ids[]"]'))
      .filter((caja) => !caja.closest('tr').hidden);

    master.checked = visibles.length > 0 && visibles.every((caja) => caja.checked);
  };

  // Panel: la tabla arranca con los 5 archivos más recientes y los filtros actúan al
  // instante sobre lo ya cargado; «Filtrar» vuelve a consultar el servidor.
  const montarTabla = (idFilas, idVacio, idContador, rotulo, campos) => {
    const tbody = document.getElementById(idFilas);

    if (!tbody) {
      return;
    }

    const vacio = idVacio ? document.getElementById(idVacio) : null;
    const contador = idContador ? document.getElementById(idContador) : null;
    const filas = Array.from(tbody.querySelectorAll('tr'));
    const controles = campos
      .map((campo) => ({ ...campo, el: document.getElementById(campo.id) }))
      .filter((campo) => campo.el !== null);

    const activo = (campo) => {
      if (campo.tipo === 'check') {
        return campo.el.checked;
      }
      if (campo.tipo === 'select') {
        return campo.el.value !== campo.defecto;
      }

      return campo.el.value.trim() !== '';
    };

    const coincide = (tr, campo) => {
      if (campo.tipo === 'check') {
        return !campo.el.checked || tr.dataset[campo.campo] === '1';
      }
      if (campo.tipo === 'select') {
        return campo.el.value === campo.defecto || tr.dataset[campo.campo] === campo.el.value;
      }

      const texto = campo.el.value.trim().toLowerCase();

      return texto === '' || (tr.dataset[campo.campo] || '').includes(texto);
    };

    const aplicar = () => {
      const hayFiltro = controles.some(activo);
      let visibles = 0;

      filas.forEach((tr) => {
        const mostrar = (hayFiltro || tr.dataset.reciente === '1')
          && controles.every((campo) => coincide(tr, campo));

        tr.hidden = !mostrar;
        if (mostrar) {
          visibles += 1;
        }
      });

      if (vacio) {
        vacio.hidden = visibles !== 0;
      }
      if (contador) {
        contador.textContent = visibles === filas.length
          ? ''
          : `Mostrando ${visibles} de ${filas.length} ${rotulo}.`;
      }
      sincronizarSeleccion();
    };

    controles.forEach((campo) => {
      campo.el.addEventListener(campo.tipo === 'text' ? 'input' : 'change', aplicar);
    });
    aplicar();
  };

  if (document.getElementById('filas-archivos')) {
    montarTabla('filas-archivos', 'sin-resultados-archivos', 'conteo-archivos', 'archivos', [
      { id: 'filtro-nombre', campo: 'nombre', tipo: 'text' },
      { id: 'filtro-estado', campo: 'estado', tipo: 'select', defecto: 'todos' },
      { id: 'filtro-nunca', campo: 'nunca', tipo: 'check' },
    ]);
  } else {
    montarTabla('filas-destinatarios', 'sin-resultados-destinatarios', 'conteo-destinatarios', 'destinatarios', [
      { id: 'filtro-correo', campo: 'correo', tipo: 'text' },
      { id: 'filtro-archivo', campo: 'archivo', tipo: 'text' },
      { id: 'filtro-nunca', campo: 'nunca', tipo: 'check' },
    ]);
  }

  // Panel: seleccionar todos los archivos visibles de la purga.
  const todos = document.getElementById('seleccionar-todos');
  if (todos) {
    const cajas = () => Array.from(document.querySelectorAll('input[name="ids[]"]'));

    todos.addEventListener('change', () => {
      cajas().forEach((caja) => {
        if (!caja.closest('tr').hidden) {
          caja.checked = todos.checked;
        }
      });
    });

    cajas().forEach((caja) => caja.addEventListener('change', sincronizarSeleccion));
  }

  // Panel: confirmar antes de una acción destructiva. Solo la purga exige
  // marcar archivos; otros formularios (p. ej. regenerar la API-Key) no.
  document.querySelectorAll('form[data-confirmar]').forEach((form) => {
    const tieneSeleccion = form.querySelector('input[name="ids[]"]') !== null;

    form.addEventListener('submit', (evento) => {
      if (tieneSeleccion && form.querySelectorAll('input[name="ids[]"]:checked').length === 0) {
        evento.preventDefault();
        window.alert('Selecciona al menos un archivo.');
        return;
      }
      if (!window.confirm(form.dataset.confirmar)) {
        evento.preventDefault();
      }
    });
  });
})();
