<!DOCTYPE html>
<html>
<head>
    <title>{{ $notebook->title }}</title>
    <style>
        body { font-family: 'Inter', sans-serif; }
        .page { page-break-after: always; position: relative; width: 100%; height: 1000px; }
        .stroke { position: absolute; }
    </style>
</head>
<body>
    <h1>{{ $notebook->title }}</h1>
    <p>Autor: {{ $notebook->author_name }}</p>
    <p>Disciplina: {{ $notebook->subject->name }}</p>

    @foreach($notebook->pages as $page)
        <div class="page">
            <h3>Página {{ $page->page_number }}</h3>
            <!-- Aqui seriam renderizados os traços em SVG ou imagens -->
            <p>{{ $page->extracted_text }}</p>
        </div>
    @endforeach
</body>
</html>
