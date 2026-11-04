/* Filtro de la tabla de "Mis archivos". Sin dependencias. */
(() => {
  const buscar = document.getElementById('buscar');
  const vigencia = document.getElementById('vigencia');
  const filas = Array.from(document.querySelectorAll('#filas tr'));
  const vacio = document.getElementById('sin-resultados');

  if (filas.length === 0) {
    return;
  }

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
})();
