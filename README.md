# Lucuma Marketplace (plugin WordPress)

Núcleo del marketplace de maquinaria (fase 1, equivalente a Machinery Trader). Outline del proyecto:
Proyectos → Ecosistema Heavy Machinery → Archivos → «Outline del proyecto».

Despliegue: Deployer for Git (archivo principal en la raíz del repo). Tras actualizar, el plugin
refresca solo el rol Dealer y las reglas de URL una vez por versión (`lm_version`).

## Modelo
- `lm_equipment` (REST `/wp/v2/equipment`, URL `/equipment/<slug>/`): un equipo; el autor es el dealer.
- `lm_category` jerárquica (`/for-sale/construction/excavators/`); cada categoría lleva en `lm_specs`
  la lista de especificaciones. Un equipo usa las de su categoría y sus ancestros.
- `lm_make` (`/make/caterpillar/`), `lm_model` (`/model/caterpillar-320/`, meta `lm_make`). Al guardar,
  la marca se alinea con la del modelo.
- Meta: `lm_year`, `lm_hours`, `lm_mileage`, `lm_price`, `lm_call_for_price`, `lm_condition`
  (new/used/refurbished), `lm_status` (available/pending/sold), `lm_stock_number`, `lm_city`,
  `lm_state` (2 letras), `lm_zip` (5 dígitos), `lm_country` (US), `lm_video_url`, `lm_gallery` (ids),
  `lm_specs` (objeto). `lm_serial` es privado: solo lo lee/escribe quien puede editar el equipo.
- Rol `lm_dealer`: crea, publica, edita y borra solo lo suyo; asigna términos pero no edita el catálogo.

## API `lucuma-mkt/v1`
- `GET /catalog`: categorías con sus specs y marcas (público).
- `POST /seed`: carga `data/catalogo.json` (admin; idempotente por slug, pisa las specs).
- `POST /models` `{make, model}`: busca o crea el modelo bajo una marca existente (dealers y admin; la marca acepta alias, p. ej. "Deere").
- `GET /resolve?category=…&make=…`: traduce un nombre (de Sandhills u otro origen) a la categoría o marca del catálogo, por nombre, slug o alias (`lm_aliases`).

## Panel del dealer (0.3.0)
- Página `/dealer/` (shortcode `[lm_dealer_panel]`, se crea sola). App sin dependencias en `assets/panel.js`:
  lista con estado rápido, formulario (categoría → specs, marca → modelos, fotos arrastrando, precio,
  ubicación), perfil de empresa con logo. Publica directo (decisión 2a); "Save as draft" opcional.
- Los dealers no entran al wp-admin ni ven la barra; al iniciar sesión van al panel. Solo ven sus fotos.
- Invitación (decisión 1a): `POST /lucuma-mkt/v1/dealers {email, company, name?, phone?, city?, state?}` →
  enlace para crear contraseña (no depende del correo). También vale Usuarios → Añadir con rol Dealer.
- API del panel: `/my/equipment` (GET/POST), `/my/equipment/{id}` (GET/POST/DELETE),
  `/my/equipment/{id}/status`, `/models?make=`, `/my/profile` (GET/POST).

## Piezas públicas para Bricks (0.4.0)
Shortcodes que Bricks coloca con su elemento Shortcode (diseño en Bricks, lógica aquí; estilos en
`assets/front.css`): `[lm_search]`, `[lm_categories]`, `[lm_makes]`, `[lm_grid count="8"]` (y `main="1"`
en el archivo, con filtros `?q=` `?eqcat=` `?make=` `?dealer=` y paginación), `[lm_filters]`,
`[lm_archive_title]`; en la ficha `[lm_breadcrumbs]` `[lm_price]` `[lm_gallery]` `[lm_facts]` `[lm_specs]`
`[lm_dealer]`. Nunca usar `?cat=` (reservado por WordPress). Vendidos al final del listado.

## Importador (0.5.0)
Panel → Import (`#/import`): plantilla CSV, subir archivo, vista previa por fila (categoría y marca
resueltas con alias; errores marcados) e importación en tandas con barra de avance. Columnas:
`stock_number, category, make, model, year, hours, mileage, price, call_for_price, condition, status,
serial, city, state, zip, title, description, video_url, photos` (+ `spec:<clave>`); acepta alias
(`stock`, `manufacturer`, `vin`, `images`…) y separador `,` o `;`. `photos`: URLs separadas por `|`.
Reimportar actualiza por número de stock (por dealer); las fotos se bajan una vez (`_lm_source_url`).
El admin importa a nombre de un dealer. API: `GET /import/template`, `POST /import` (multipart `file`
o JSON `rows`, `dealer`), `POST /import/{job}/run {limit}`. No lee Sandhills directamente (Cloudflare):
el inventario de un dealer de Sandhills entra como CSV o feed oficial.

## Siguiente (outline, orden de trabajo)
Importador CSV/Sandhills · tabla propia de búsqueda + buscador ·
contactos y medición · páginas SEO de categoría/marca/modelo · widget para la web de Kurtz.
