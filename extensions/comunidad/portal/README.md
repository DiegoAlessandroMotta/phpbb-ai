# Portal Comunitario para phpBB

Extensión gratuita para phpBB 3.3.x que añade una portada moderna conectada directamente con el foro.

## Funciones

- Página `/portal`.
- Temas recientes respetando permisos.
- Temas más visitados.
- Foros destacados.
- Nuevos miembros.
- Estadísticas generales.
- Enlaces rápidos al foro, temas activos y temas sin respuesta.
- Diseño adaptable a celulares.
- Idiomas español e inglés.
- Enlace “Portal” integrado en la navegación de phpBB.
- No modifica archivos del núcleo.

## Instalación

1. Descomprime el ZIP.
2. Copia la carpeta `ext/comunidad/portal` dentro de la carpeta `ext` de tu phpBB.
3. Entra al **Panel de Administración → Personalizar → Administrar extensiones**.
4. Busca **Portal Comunitario** y pulsa **Habilitar**.
5. Limpia la caché desde el Panel de Administración.
6. Abre `https://tu-dominio.com/foro/app.php/portal`.

Según la configuración de reescritura de URL, también puede funcionar como:

`https://tu-dominio.com/foro/portal`

## Convertir el portal en página principal

La forma segura es redirigir el dominio o una página de inicio hacia `/app.php/portal`. No reemplaces `index.php` del núcleo.

Ejemplo Apache en la raíz del dominio:

```apache
RedirectMatch 302 ^/$ /foro/app.php/portal
```

Después de comprobar que funciona, puedes cambiar `302` por `301`.

## Compatibilidad

- phpBB 3.3.x.
- PHP 7.2 o superior.
- Estilo prosilver y estilos que hereden su estructura.
- Licencia GPL-2.0-only.

## Personalización rápida

- Textos: `language/es/common.php`
- Diseño: `styles/all/theme/portal.css`
- Plantilla: `styles/all/template/portal_body.html`
- Cantidad de elementos: `controller/main.php`

## Seguridad

Las consultas solo muestran foros y temas que el usuario tiene permiso para listar o leer. La extensión usa las sesiones y permisos nativos de phpBB.
