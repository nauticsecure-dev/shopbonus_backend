@php
    $brandHome = rtrim((string) config('shopbonus.identity.frontend_url'), '/');
@endphp
<a href="{{ $brandHome }}" style="display:inline-block;text-decoration:none">
    <img src="{{ $brandHome }}/brand/logo-full.png" alt="ShopBonus" width="180" height="44" style="display:block;width:180px;height:44px;border:0;object-fit:contain">
</a>
