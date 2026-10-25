/*
 * MOTOCONS · RF-02 Ventas presenciales
 * Mejoras de interfaz. Ninguna regla de negocio depende de este archivo: el servidor
 * valida y calcula todo de nuevo al registrar la venta.
 */
(function () {
  'use strict';

  // Formato de pesos: igual que Dinero::formatear() en PHP (centavos -> "$ 1.234.567").
  function pesos(centavos) {
    var decimales = centavos % 100 === 0 ? 0 : 2;
    return '$ ' + (centavos / 100).toLocaleString('es-CO', {
      minimumFractionDigits: decimales,
      maximumFractionDigits: decimales
    });
  }

  // Igual que Dinero::aplicarPorcentaje(): redondeo al centavo más cercano.
  function aplicarPorcentaje(centavos, puntosBasicos) {
    return Math.floor((centavos * puntosBasicos + 5000) / 10000);
  }

  // Evita el doble envío de formularios marcados con data-un-envio.
  document.querySelectorAll('form[data-un-envio]').forEach(function (form) {
    form.addEventListener('submit', function () {
      form.querySelectorAll('button[type="submit"]').forEach(function (b) {
        b.disabled = true;
        b.classList.add('enviando');
      });
    });
  });

  // Botón de impresión del comprobante (sin manejadores en línea, por la política CSP).
  document.querySelectorAll('[data-imprimir]').forEach(function (boton) {
    boton.addEventListener('click', function () { window.print(); });
  });

  // Filtro de texto para un <select> largo (búsqueda de cliente).
  document.querySelectorAll('[data-filtrar-select]').forEach(function (entrada) {
    var select = document.getElementById(entrada.getAttribute('data-filtrar-select'));
    if (!select) { return; }
    entrada.addEventListener('input', function () {
      var texto = entrada.value.trim().toLowerCase();
      var primeraVisible = null;
      Array.prototype.forEach.call(select.options, function (opcion) {
        if (!opcion.value) { return; }
        var visible = texto === '' || opcion.text.toLowerCase().indexOf(texto) !== -1;
        opcion.hidden = !visible;
        if (visible && primeraVisible === null) { primeraVisible = opcion; }
      });
      var actual = select.options[select.selectedIndex];
      if (texto !== '' && primeraVisible && (!actual || actual.hidden || !actual.value)) {
        select.value = primeraVisible.value;
      }
    });
  });

  var form = document.getElementById('form-venta');
  if (!form) { return; }

  var siguienteIndice = { motos: 1000, productos: 1000 };

  function recalcular() {
    var subtotal = 0;
    var descuento = 0;
    form.querySelectorAll('tr.linea').forEach(function (fila) {
      var select = fila.querySelector('.linea-articulo');
      var cantidadInput = fila.querySelector('.linea-cantidad');
      var opcion = select.options[select.selectedIndex];
      var celdas = {
        precio: fila.querySelector('.linea-precio'),
        descuento: fila.querySelector('.linea-descuento'),
        subtotal: fila.querySelector('.linea-subtotal')
      };
      fila.classList.remove('excede-stock');
      if (!opcion || !opcion.value) {
        celdas.precio.textContent = celdas.descuento.textContent = celdas.subtotal.textContent = '—';
        return;
      }
      var precio = parseInt(opcion.getAttribute('data-precio'), 10) || 0;
      var puntos = parseInt(opcion.getAttribute('data-puntos'), 10) || 0;
      var stock = parseInt(opcion.getAttribute('data-stock'), 10) || 0;
      var cantidad = parseInt(cantidadInput.value, 10);
      if (!(cantidad > 0)) { cantidad = 0; }
      cantidadInput.max = Math.max(1, Math.min(stock, parseInt(cantidadInput.getAttribute('max'), 10) || 50));
      if (cantidad > stock) { fila.classList.add('excede-stock'); }

      var descuentoLinea = aplicarPorcentaje(precio, puntos) * cantidad;
      var subtotalLinea = precio * cantidad;
      celdas.precio.textContent = pesos(precio);
      celdas.descuento.textContent = descuentoLinea > 0 ? '− ' + pesos(descuentoLinea) : '—';
      celdas.subtotal.textContent = pesos(subtotalLinea - descuentoLinea);
      subtotal += subtotalLinea;
      descuento += descuentoLinea;
    });
    document.getElementById('resumen-subtotal').textContent = pesos(subtotal);
    document.getElementById('resumen-descuento').textContent = descuento > 0 ? '− ' + pesos(descuento) : '$ 0';
    document.getElementById('resumen-total').textContent = pesos(subtotal - descuento);
  }

  // Agregar líneas a partir de las plantillas <template>, con un índice único en los nombres.
  form.querySelectorAll('[data-agregar-linea]').forEach(function (boton) {
    boton.addEventListener('click', function () {
      var grupo = boton.getAttribute('data-agregar-linea');
      var plantilla = document.getElementById('plantilla-' + grupo);
      var cuerpo = form.querySelector('table[data-grupo="' + grupo + '"] tbody');
      var indice = siguienteIndice[grupo]++;
      var html = plantilla.innerHTML.replace(new RegExp(grupo + '\\[0\\]', 'g'), grupo + '[' + indice + ']');
      cuerpo.insertAdjacentHTML('beforeend', html);
      var nueva = cuerpo.lastElementChild.querySelector('select');
      if (nueva) { nueva.focus(); }
      recalcular();
    });
  });

  form.addEventListener('click', function (evento) {
    var boton = evento.target.closest('[data-quitar-linea]');
    if (!boton) { return; }
    boton.closest('tr').remove();
    recalcular();
  });

  form.addEventListener('change', recalcular);
  form.addEventListener('input', function (evento) {
    if (evento.target.classList.contains('linea-cantidad')) { recalcular(); }
  });

  // Mostrar solo los requisitos de la forma de pago elegida.
  function actualizarRequisitos() {
    var elegido = form.querySelector('input[name="metodo_pago"]:checked');
    form.querySelectorAll('[data-para-metodo]').forEach(function (bloque) {
      var activo = elegido && elegido.value === bloque.getAttribute('data-para-metodo');
      bloque.hidden = !activo;
      bloque.querySelectorAll('input').forEach(function (campo) {
        campo.required = activo;
      });
    });
  }
  form.querySelectorAll('input[name="metodo_pago"]').forEach(function (radio) {
    radio.addEventListener('change', actualizarRequisitos);
  });

  actualizarRequisitos();
  recalcular();
})();
