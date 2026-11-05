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

## Siguiente (outline, orden de trabajo)
Importador CSV/Sandhills · tabla propia de búsqueda + buscador ·
contactos y medición · páginas SEO de categoría/marca/modelo · widget para la web de Kurtz.
