{{--
    The document kind and number have no canvas partial of their own: each style
    places them in its own frame -- inside the classic box, the modern masthead,
    the minimal topline or the rail's opening lines -- and `documents/pdf.blade.php`
    skips `title` in the canvas loop deliberately. This file exists only so the
    block census (`ViewIntegrityTest`) sees every registered block as renderable;
    it is never included.
--}}
