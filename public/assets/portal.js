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

  // Panel: cada tabla arranca con los 5 archivos más recientes; el buscador filtra
  // al vuelo por nombre o correo sobre lo ya cargado y va revelando el resto.
  const montarBuscador = (idBuscar, idFilas, idVacio) => {
    const input = document.getElementById(idBuscar);
    const tbody = document.getElementById(idFilas);
    const vacio = document.getElementById(idVacio);

    if (!input || !tbody) {
      return;
    }

    const filas = Array.from(tbody.querySelectorAll('tr'));

    const aplicar = () => {
      const q = input.value.trim().toLowerCase();
      let visibles = 0;

      filas.forEach((tr) => {
        const texto = tr.dataset.buscar || '';
        const mostrar = q === '' ? tr.dataset.reciente === '1' : texto.includes(q);

        tr.hidden = !mostrar;
        if (mostrar) {
          visibles += 1;
        }
      });

      if (vacio) {
        vacio.hidden = visibles !== 0;
      }
    };

    input.addEventListener('input', aplicar);
    aplicar();
  };

  montarBuscador('buscar-archivos', 'filas-archivos', 'sin-resultados-archivos');
  montarBuscador('buscar-destinatarios', 'filas-destinatarios', 'sin-resultados-destinatarios');

  // Panel: seleccionar todos los archivos de la purga.
  const todos = document.getElementById('seleccionar-todos');
  if (todos) {
    const cajas = () => Array.from(document.querySelectorAll('input[name="ids[]"]'));

    todos.addEventListener('change', () => {
      cajas().forEach((caja) => {
        caja.checked = todos.checked;
      });
    });

    cajas().forEach((caja) => caja.addEventListener('change', () => {
      const lista = cajas();
      todos.checked = lista.length > 0 && lista.every((otra) => otra.checked);
    }));
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
