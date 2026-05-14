<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <?php /* CMS:BLOCK name=meta_title role=meta custom=1 system=1 start */ ?>
    <title>Harbor &amp; Pine Coffee Roasters | Small-Batch Specialty Coffee in Portland</title>
    <?php /* CMS:BLOCK name=meta_title end */ ?>

    <?php /* CMS:BLOCK name=meta_description role=meta custom=1 system=1 start */ ?>
    <meta name="description" content="Harbor &amp; Pine roasts single-origin, direct-trade coffee in small batches from our Portland, Oregon roastery. Slow roasted for clarity, character, and craft.">
    <?php /* CMS:BLOCK name=meta_description end */ ?>

    <?php /* CMS:BLOCK name=meta_keywords role=meta custom=1 system=1 start */ ?>
    <meta name="keywords" content="specialty coffee, Portland coffee roaster, single origin coffee, direct trade coffee, small batch roastery, Ethiopia Yirgacheffe, Colombia Huila, Sumatra Mandheling">
    <?php /* CMS:BLOCK name=meta_keywords end */ ?>

    <?php /* CMS:BLOCK name=meta_canonical role=meta custom=1 system=1 start */ ?>
    <link rel="canonical" href="https://cms.dig.ge/demo/">
    <?php /* CMS:BLOCK name=meta_canonical end */ ?>

    <?php /* CMS:BLOCK name=meta_og role=meta custom=1 system=1 start */ ?>
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Harbor &amp; Pine Coffee Roasters">
    <meta property="og:title" content="Harbor &amp; Pine Coffee Roasters | Small-Batch Specialty Coffee">
    <meta property="og:description" content="Single-origin, direct-trade coffee, slow roasted in Portland, Oregon since 2016.">
    <meta property="og:url" content="https://cms.dig.ge/demo/">
    <meta property="og:image" content="https://images.unsplash.com/photo-1442550528053-c431ecb55509?auto=format&fit=crop&w=1600&q=80">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Harbor &amp; Pine Coffee Roasters">
    <meta name="twitter:description" content="Small-batch, single-origin coffee from Portland, Oregon.">
    <meta name="twitter:image" content="https://images.unsplash.com/photo-1442550528053-c431ecb55509?auto=format&fit=crop&w=1600&q=80">
    <?php /* CMS:BLOCK name=meta_og end */ ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        serif: ['Fraunces', 'ui-serif', 'Georgia', 'serif'],
                        sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                    },
                    colors: {
                        cream: {
                            50:  '#fbf7f1',
                            100: '#f6efe2',
                            200: '#ecdfc5',
                        },
                        bark: {
                            700: '#4a2e1c',
                            800: '#36200f',
                            900: '#22150a',
                        },
                        terracotta: {
                            500: '#c0563b',
                            600: '#a8462e',
                        },
                        moss: {
                            600: '#4a6b4a',
                            700: '#365236',
                        },
                    },
                },
            },
        };
    </script>

    <style>
        html { scroll-behavior: smooth; }
        body {
            background-color: #fbf7f1;
            color: #22150a;
            font-feature-settings: "ss01", "ss02";
        }
        .font-display { font-family: 'Fraunces', Georgia, serif; font-optical-sizing: auto; }
        .grain {
            background-image:
                radial-gradient(rgba(54, 32, 15, 0.04) 1px, transparent 1px),
                radial-gradient(rgba(54, 32, 15, 0.03) 1px, transparent 1px);
            background-size: 24px 24px, 18px 18px;
            background-position: 0 0, 12px 9px;
        }
        .hero-gradient {
            background:
                linear-gradient(135deg, rgba(34, 21, 10, 0.85) 0%, rgba(74, 46, 28, 0.65) 50%, rgba(192, 86, 59, 0.45) 100%),
                url('https://images.unsplash.com/photo-1442550528053-c431ecb55509?auto=format&fit=crop&w=2000&q=80') center/cover no-repeat;
        }
        .story-gradient {
            background:
                linear-gradient(180deg, rgba(34, 21, 10, 0.78) 0%, rgba(34, 21, 10, 0.65) 100%),
                url('https://images.unsplash.com/photo-1559525839-d9acfd02da97?auto=format&fit=crop&w=2000&q=80') center/cover no-repeat;
        }
    </style>
</head>
<body class="font-sans antialiased">

    <?php /* CMS:BLOCK name=header role=content custom=1 start */ ?>
    <header class="absolute top-0 left-0 right-0 z-30">
        <div class="max-w-7xl mx-auto px-6 lg:px-10 py-6 flex items-center justify-between">
            <a href="#" class="flex items-center gap-3 text-cream-50">
                <span class="inline-flex items-center justify-center w-10 h-10 rounded-full border border-cream-100/40">
                    <span class="font-display text-xl font-semibold">H</span>
                </span>
                <span class="font-display text-lg tracking-wide">Harbor &amp; Pine</span>
            </a>
            <nav class="hidden md:flex items-center gap-10 text-sm text-cream-50/90">
                <a href="#about" class="hover:text-cream-50 transition">About</a>
                <a href="#products" class="hover:text-cream-50 transition">Coffee</a>
                <a href="#story" class="hover:text-cream-50 transition">Our Story</a>
                <a href="#visit" class="hover:text-cream-50 transition">Visit</a>
            </nav>
            <a href="#products" class="hidden md:inline-flex items-center gap-2 px-5 py-2.5 rounded-full bg-cream-50 text-bark-900 text-sm font-medium hover:bg-cream-100 transition">
                Shop Coffee
            </a>
        </div>
    </header>
    <?php /* CMS:BLOCK name=header end */ ?>

    <?php /* CMS:BLOCK name=hero role=content custom=1 start */ ?>
    <section class="hero-gradient relative min-h-screen flex items-center">
        <div class="max-w-7xl mx-auto px-6 lg:px-10 py-32 lg:py-40 w-full">
            <div class="max-w-3xl">
                <p class="text-cream-100/80 text-sm tracking-[0.25em] uppercase mb-6">Small-batch roasting since 2016</p>
                <h1 class="font-display text-5xl sm:text-6xl lg:text-7xl text-cream-50 leading-[1.05] font-medium">
                    Coffee with a sense of place.
                </h1>
                <p class="mt-8 text-lg lg:text-xl text-cream-100/85 max-w-2xl leading-relaxed">
                    We work directly with growers across three continents, then roast each lot slowly in Portland, Oregon — chasing clarity, sweetness, and the quiet character of a well-made cup.
                </p>
                <div class="mt-10 flex flex-wrap items-center gap-4">
                    <a href="#products" class="inline-flex items-center gap-2 px-7 py-3.5 rounded-full bg-cream-50 text-bark-900 font-medium hover:bg-cream-100 transition">
                        Browse our coffees
                        <span aria-hidden="true">&rarr;</span>
                    </a>
                    <a href="#story" class="inline-flex items-center gap-2 px-7 py-3.5 rounded-full border border-cream-50/40 text-cream-50 font-medium hover:bg-cream-50/10 transition">
                        Read our story
                    </a>
                </div>
            </div>
        </div>
        <div class="absolute bottom-8 left-0 right-0 max-w-7xl mx-auto px-6 lg:px-10">
            <div class="flex items-center gap-3 text-cream-100/70 text-xs tracking-widest uppercase">
                <span class="h-px w-12 bg-cream-100/40"></span>
                Portland, Oregon
            </div>
        </div>
    </section>
    <?php /* CMS:BLOCK name=hero end */ ?>

    <?php /* CMS:BLOCK name=about role=content custom=1 start */ ?>
    <section id="about" class="grain bg-cream-50 py-24 lg:py-32">
        <div class="max-w-6xl mx-auto px-6 lg:px-10">
            <div class="grid lg:grid-cols-12 gap-12 lg:gap-20 items-start">
                <div class="lg:col-span-5">
                    <p class="text-terracotta-600 text-xs tracking-[0.25em] uppercase mb-5">About the roastery</p>
                    <h2 class="font-display text-4xl lg:text-5xl text-bark-900 leading-tight font-medium">
                        A small roastery built on long relationships.
                    </h2>
                </div>
                <div class="lg:col-span-7 space-y-6 text-bark-800/90 text-lg leading-relaxed">
                    <p>
                        Harbor &amp; Pine started in a converted boat shop near the Willamette River with a single drum roaster, a stack of borrowed cupping bowls, and a stubborn idea: that great coffee belongs to the people who grow it.
                    </p>
                    <p>
                        Nearly a decade later, we still buy the way we started — directly from a small group of farmers and cooperatives in Ethiopia, Colombia, and Sumatra. We pay above market, we visit every year we can, and we roast in batches small enough to taste every one.
                    </p>
                    <div class="grid grid-cols-3 gap-6 pt-8 border-t border-bark-900/10">
                        <div>
                            <p class="font-display text-3xl text-bark-900">9</p>
                            <p class="text-xs uppercase tracking-wider text-bark-700/70 mt-1">Years roasting</p>
                        </div>
                        <div>
                            <p class="font-display text-3xl text-bark-900">14</p>
                            <p class="text-xs uppercase tracking-wider text-bark-700/70 mt-1">Producer partners</p>
                        </div>
                        <div>
                            <p class="font-display text-3xl text-bark-900">3</p>
                            <p class="text-xs uppercase tracking-wider text-bark-700/70 mt-1">Origin countries</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <?php /* CMS:BLOCK name=about end */ ?>

    <?php /* CMS:BLOCK name=products role=content custom=1 start */ ?>
    <section id="products" class="bg-cream-100 py-24 lg:py-32">
        <div class="max-w-7xl mx-auto px-6 lg:px-10">
            <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-6 mb-16">
                <div class="max-w-2xl">
                    <p class="text-terracotta-600 text-xs tracking-[0.25em] uppercase mb-5">Current offerings</p>
                    <h2 class="font-display text-4xl lg:text-5xl text-bark-900 leading-tight font-medium">
                        This season's coffees.
                    </h2>
                </div>
                <p class="text-bark-800/80 max-w-md leading-relaxed">
                    Roasted to order on Tuesdays and Fridays. Whole bean, 12 oz bags. Free shipping on orders over $40.
                </p>
            </div>

            <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-6 lg:gap-8">

                <article class="group bg-cream-50 rounded-2xl overflow-hidden border border-bark-900/5 hover:shadow-xl hover:shadow-bark-900/10 transition-all duration-300">
                    <div class="aspect-[4/5] overflow-hidden">
                        <img src="https://images.unsplash.com/photo-1559525839-d9acfd02da97?auto=format&fit=crop&w=800&q=80" alt="Ethiopia Yirgacheffe coffee bag" class="w-full h-full object-cover group-hover:scale-105 transition duration-700">
                    </div>
                    <div class="p-6">
                        <p class="text-xs uppercase tracking-wider text-moss-700 mb-2">Ethiopia</p>
                        <h3 class="font-display text-2xl text-bark-900 mb-3">Yirgacheffe</h3>
                        <p class="text-sm text-bark-700/80 leading-relaxed mb-5">
                            Floral, bergamot, lemon. Washed process from the Gedeo zone — bright and tea-like.
                        </p>
                        <div class="flex items-center justify-between pt-4 border-t border-bark-900/10">
                            <span class="font-display text-xl text-bark-900">$19</span>
                            <a href="#" class="text-xs uppercase tracking-widest text-terracotta-600 hover:text-terracotta-500">Add to cart &rarr;</a>
                        </div>
                    </div>
                </article>

                <article class="group bg-cream-50 rounded-2xl overflow-hidden border border-bark-900/5 hover:shadow-xl hover:shadow-bark-900/10 transition-all duration-300">
                    <div class="aspect-[4/5] overflow-hidden">
                        <img src="https://images.unsplash.com/photo-1611854779393-1b2da9d400fe?auto=format&fit=crop&w=800&q=80" alt="Colombia Huila coffee bag" class="w-full h-full object-cover group-hover:scale-105 transition duration-700">
                    </div>
                    <div class="p-6">
                        <p class="text-xs uppercase tracking-wider text-moss-700 mb-2">Colombia</p>
                        <h3 class="font-display text-2xl text-bark-900 mb-3">Huila</h3>
                        <p class="text-sm text-bark-700/80 leading-relaxed mb-5">
                            Caramel, milk chocolate, orange. Grown by the Pescador family at 1,750 m.
                        </p>
                        <div class="flex items-center justify-between pt-4 border-t border-bark-900/10">
                            <span class="font-display text-xl text-bark-900">$17</span>
                            <a href="#" class="text-xs uppercase tracking-widest text-terracotta-600 hover:text-terracotta-500">Add to cart &rarr;</a>
                        </div>
                    </div>
                </article>

                <article class="group bg-cream-50 rounded-2xl overflow-hidden border border-bark-900/5 hover:shadow-xl hover:shadow-bark-900/10 transition-all duration-300">
                    <div class="aspect-[4/5] overflow-hidden">
                        <img src="https://images.unsplash.com/photo-1610632380989-680fe40816c6?auto=format&fit=crop&w=800&q=80" alt="Sumatra Mandheling coffee bag" class="w-full h-full object-cover group-hover:scale-105 transition duration-700">
                    </div>
                    <div class="p-6">
                        <p class="text-xs uppercase tracking-wider text-moss-700 mb-2">Indonesia</p>
                        <h3 class="font-display text-2xl text-bark-900 mb-3">Sumatra Mandheling</h3>
                        <p class="text-sm text-bark-700/80 leading-relaxed mb-5">
                            Earthy, cedar, dark chocolate. Wet-hulled and full-bodied — a quiet, grounding cup.
                        </p>
                        <div class="flex items-center justify-between pt-4 border-t border-bark-900/10">
                            <span class="font-display text-xl text-bark-900">$18</span>
                            <a href="#" class="text-xs uppercase tracking-widest text-terracotta-600 hover:text-terracotta-500">Add to cart &rarr;</a>
                        </div>
                    </div>
                </article>

                <article class="group bg-cream-50 rounded-2xl overflow-hidden border border-bark-900/5 hover:shadow-xl hover:shadow-bark-900/10 transition-all duration-300">
                    <div class="aspect-[4/5] overflow-hidden">
                        <img src="https://images.unsplash.com/photo-1587049352846-4a222e784d38?auto=format&fit=crop&w=800&q=80" alt="Harbor House Blend coffee bag" class="w-full h-full object-cover group-hover:scale-105 transition duration-700">
                    </div>
                    <div class="p-6">
                        <p class="text-xs uppercase tracking-wider text-moss-700 mb-2">House Blend</p>
                        <h3 class="font-display text-2xl text-bark-900 mb-3">Harbor</h3>
                        <p class="text-sm text-bark-700/80 leading-relaxed mb-5">
                            Balanced, nutty, smooth. Our everyday blend — built for milk drinks and quiet mornings.
                        </p>
                        <div class="flex items-center justify-between pt-4 border-t border-bark-900/10">
                            <span class="font-display text-xl text-bark-900">$16</span>
                            <a href="#" class="text-xs uppercase tracking-widest text-terracotta-600 hover:text-terracotta-500">Add to cart &rarr;</a>
                        </div>
                    </div>
                </article>

            </div>
        </div>
    </section>
    <?php /* CMS:BLOCK name=products end */ ?>

    <?php /* CMS:BLOCK name=story role=content custom=1 start */ ?>
    <section id="story" class="story-gradient relative py-28 lg:py-40">
        <div class="max-w-5xl mx-auto px-6 lg:px-10">
            <div class="grid lg:grid-cols-12 gap-12">
                <div class="lg:col-span-5">
                    <p class="text-cream-100/70 text-xs tracking-[0.25em] uppercase mb-5">Why we do this</p>
                    <h2 class="font-display text-4xl lg:text-5xl text-cream-50 leading-tight font-medium">
                        The long way around is usually the right way.
                    </h2>
                </div>
                <div class="lg:col-span-7 space-y-6 text-cream-100/85 text-lg leading-relaxed">
                    <p>
                        It would be cheaper to buy our green coffee on the spot market. It would be faster to roast hot and dark and call it a day. It would be easier to put a logo on a bag from someone else's warehouse and call ourselves a brand.
                    </p>
                    <p>
                        We don't, because the people who grow this coffee deserve more than that, and so do the people who drink it. Every bag we sell starts with a name — a farmer, a cooperative, a hillside — and ends with someone, somewhere, sitting down for a quiet minute with a cup that feels like it was made on purpose.
                    </p>
                    <p class="font-display italic text-xl text-cream-50 pt-4">
                        That's the whole job, really.
                    </p>
                </div>
            </div>
        </div>
    </section>
    <?php /* CMS:BLOCK name=story end */ ?>

    <?php /* CMS:BLOCK name=visit role=content custom=1 start */ ?>
    <section id="visit" class="bg-cream-50 py-24 lg:py-32">
        <div class="max-w-6xl mx-auto px-6 lg:px-10">
            <div class="grid lg:grid-cols-2 gap-16 items-center">
                <div>
                    <p class="text-terracotta-600 text-xs tracking-[0.25em] uppercase mb-5">Visit the roastery</p>
                    <h2 class="font-display text-4xl lg:text-5xl text-bark-900 leading-tight font-medium mb-8">
                        Stop by for a cup. We almost always have a fresh pot on.
                    </h2>
                    <div class="space-y-6 text-bark-800/90">
                        <div>
                            <p class="text-xs uppercase tracking-widest text-bark-700/60 mb-1">Address</p>
                            <p class="text-lg">2418 NW Vaughn Street<br>Portland, Oregon 97210</p>
                        </div>
                        <div>
                            <p class="text-xs uppercase tracking-widest text-bark-700/60 mb-1">Hours</p>
                            <p class="text-lg">
                                Tuesday – Friday &nbsp; 7a – 4p<br>
                                Saturday – Sunday &nbsp; 8a – 3p<br>
                                <span class="text-bark-700/70">Closed Mondays</span>
                            </p>
                        </div>
                        <div>
                            <p class="text-xs uppercase tracking-widest text-bark-700/60 mb-1">Get in touch</p>
                            <p class="text-lg">
                                <a href="mailto:hello@harborandpine.test" class="underline decoration-terracotta-500/40 underline-offset-4 hover:decoration-terracotta-500">hello@harborandpine.test</a><br>
                                (503) 555&ndash;0142
                            </p>
                        </div>
                    </div>
                </div>
                <div class="relative">
                    <div class="aspect-[4/5] rounded-2xl overflow-hidden">
                        <img src="https://images.unsplash.com/photo-1453614512568-c4024d13c247?auto=format&fit=crop&w=1000&q=80" alt="Inside the Harbor and Pine roastery" class="w-full h-full object-cover">
                    </div>
                    <div class="absolute -bottom-6 -left-6 bg-bark-900 text-cream-50 px-6 py-5 rounded-xl max-w-[220px] hidden lg:block">
                        <p class="font-display text-lg leading-snug">Cuppings every Saturday at 10am.</p>
                        <p class="text-xs text-cream-100/70 mt-1">Free, no reservation needed.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <?php /* CMS:BLOCK name=visit end */ ?>

    <?php /* CMS:BLOCK name=testimonials role=content custom=1 start */ ?>
    <section class="bg-bark-900 text-cream-50 py-24 lg:py-32">
        <div class="max-w-6xl mx-auto px-6 lg:px-10">
            <p class="text-terracotta-500 text-xs tracking-[0.25em] uppercase mb-5 text-center">Kind words</p>
            <h2 class="font-display text-3xl lg:text-4xl text-cream-50 leading-tight font-medium text-center max-w-2xl mx-auto mb-16">
                From people who drink a lot of coffee.
            </h2>
            <div class="grid md:grid-cols-3 gap-8">

                <figure class="bg-bark-800/60 rounded-2xl p-8 border border-cream-100/10">
                    <blockquote class="font-display text-xl leading-relaxed text-cream-50">
                        &ldquo;The Yirgacheffe is the best cup I've had outside of Addis. Every bag tastes like someone cared about it.&rdquo;
                    </blockquote>
                    <figcaption class="mt-6 pt-6 border-t border-cream-100/10">
                        <p class="text-cream-50">Mira Okafor</p>
                        <p class="text-xs uppercase tracking-wider text-cream-100/60 mt-1">Café owner, Seattle</p>
                    </figcaption>
                </figure>

                <figure class="bg-bark-800/60 rounded-2xl p-8 border border-cream-100/10">
                    <blockquote class="font-display text-xl leading-relaxed text-cream-50">
                        &ldquo;I've been buying Harbor &amp; Pine for four years. Quietly the most consistent roaster on the West Coast.&rdquo;
                    </blockquote>
                    <figcaption class="mt-6 pt-6 border-t border-cream-100/10">
                        <p class="text-cream-50">Daniel Reeve</p>
                        <p class="text-xs uppercase tracking-wider text-cream-100/60 mt-1">Home roaster, Bend</p>
                    </figcaption>
                </figure>

                <figure class="bg-bark-800/60 rounded-2xl p-8 border border-cream-100/10">
                    <blockquote class="font-display text-xl leading-relaxed text-cream-50">
                        &ldquo;Their Saturday cupping changed how I taste coffee. Generous, unpretentious, real.&rdquo;
                    </blockquote>
                    <figcaption class="mt-6 pt-6 border-t border-cream-100/10">
                        <p class="text-cream-50">Anika Lindqvist</p>
                        <p class="text-xs uppercase tracking-wider text-cream-100/60 mt-1">Pastry chef, Portland</p>
                    </figcaption>
                </figure>

            </div>
        </div>
    </section>
    <?php /* CMS:BLOCK name=testimonials end */ ?>

    <?php /* CMS:BLOCK name=footer role=content custom=1 start */ ?>
    <footer class="bg-bark-900 text-cream-100/80 border-t border-cream-100/10">
        <div class="max-w-7xl mx-auto px-6 lg:px-10 py-16">
            <div class="grid md:grid-cols-12 gap-12">
                <div class="md:col-span-5">
                    <a href="#" class="flex items-center gap-3 text-cream-50 mb-4">
                        <span class="inline-flex items-center justify-center w-10 h-10 rounded-full border border-cream-100/40">
                            <span class="font-display text-xl font-semibold">H</span>
                        </span>
                        <span class="font-display text-lg tracking-wide">Harbor &amp; Pine</span>
                    </a>
                    <p class="text-sm leading-relaxed max-w-sm text-cream-100/70">
                        Small-batch, single-origin coffee, roasted slowly in Portland, Oregon. Direct trade since 2016.
                    </p>
                </div>
                <div class="md:col-span-2">
                    <p class="text-xs uppercase tracking-widest text-cream-100/50 mb-4">Shop</p>
                    <ul class="space-y-2 text-sm">
                        <li><a href="#products" class="hover:text-cream-50">Single Origin</a></li>
                        <li><a href="#products" class="hover:text-cream-50">Blends</a></li>
                        <li><a href="#" class="hover:text-cream-50">Subscriptions</a></li>
                        <li><a href="#" class="hover:text-cream-50">Gift Cards</a></li>
                    </ul>
                </div>
                <div class="md:col-span-2">
                    <p class="text-xs uppercase tracking-widest text-cream-100/50 mb-4">Roastery</p>
                    <ul class="space-y-2 text-sm">
                        <li><a href="#about" class="hover:text-cream-50">About</a></li>
                        <li><a href="#story" class="hover:text-cream-50">Our Story</a></li>
                        <li><a href="#visit" class="hover:text-cream-50">Visit</a></li>
                        <li><a href="#" class="hover:text-cream-50">Wholesale</a></li>
                    </ul>
                </div>
                <div class="md:col-span-3">
                    <p class="text-xs uppercase tracking-widest text-cream-100/50 mb-4">Newsletter</p>
                    <p class="text-sm mb-4 text-cream-100/70">A short note when new lots arrive. No noise.</p>
                    <form class="flex gap-2">
                        <input type="email" placeholder="you@example.com" class="flex-1 bg-bark-800 border border-cream-100/15 rounded-full px-4 py-2 text-sm text-cream-50 placeholder:text-cream-100/40 focus:outline-none focus:border-terracotta-500">
                        <button type="submit" class="px-4 py-2 rounded-full bg-terracotta-500 text-cream-50 text-sm hover:bg-terracotta-600 transition">Join</button>
                    </form>
                </div>
            </div>
            <div class="mt-16 pt-8 border-t border-cream-100/10 flex flex-col md:flex-row gap-4 md:items-center md:justify-between text-xs text-cream-100/50">
                <p>&copy; <?php echo date('Y'); ?> Harbor &amp; Pine Coffee Roasters. All rights reserved.</p>
                <p>
                    This is a demo site. Content powered by MCP CMS.
                    <a href="/cms/admin/" class="text-terracotta-500 hover:text-terracotta-500/80 ml-1">Edit in admin &rarr;</a>
                </p>
            </div>
        </div>
    </footer>
    <?php /* CMS:BLOCK name=footer end */ ?>

</body>
</html>
