'use client';

import Image from 'next/image';
import { useEffect, useState } from 'react';

type ProductImage = {
  id: number;
  image_url: string;
  is_primary: boolean;
  sort_order: number;
};

type Product = {
  id: number;
  title: string;
  price: string;
  created_at: string;
  images: ProductImage[];
};

const API_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/api';
const REFRESH_INTERVAL_MS = 30_000;

export default function ProductsPage() {
  const [products, setProducts] = useState<Product[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [selectedImages, setSelectedImages] = useState<Record<number, ProductImage>>({});

  useEffect(() => {
    let cancelled = false;

    async function load() {
      try {
        const res = await fetch(`${API_URL}/products`, { cache: 'no-store' });

        if (!res.ok) {
          throw new Error(`Request failed: ${res.status}`);
        }

        const data: Product[] = await res.json();

        if (!cancelled) {
          setProducts(data);
          setError(null);
          
          // Initialize selected images for each product
          const initialSelected: Record<number, ProductImage> = {};
          data.forEach((product) => {
            const primaryImage = product.images.find((img) => img.is_primary) ?? product.images[0];
            if (primaryImage) {
              initialSelected[product.id] = primaryImage;
            }
          });
          setSelectedImages(initialSelected);
        }
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : 'Failed to load products');
        }
      } finally {
        if (!cancelled) {
          setLoading(false);
        }
      }
    }

    load();
    const interval = setInterval(load, REFRESH_INTERVAL_MS);

    return () => {
      cancelled = true;
      clearInterval(interval);
    };
  }, []);

  const handleImageSelect = (productId: number, image: ProductImage) => {
    setSelectedImages((prev) => ({
      ...prev,
      [productId]: image,
    }));
  };

  return (
    <main className="page">
      <div className="header">
        <h1 className="page-title">Scraper</h1>
      </div>

      {loading && <p className="empty-state">Loading products…</p>}

      {!loading && error && (
        <p className="error-state" role="alert">
          Couldn&apos;t load products: {error}
        </p>
      )}

      {!loading && !error && products.length === 0 && (
        <p className="empty-state">No products scraped yet.</p>
      )}

      {!loading && !error && products.length > 0 && (
        <div className="grid">
          {products.map((product) => {
            const currentImage = selectedImages[product.id];
            const hasMultipleImages = product.images.length > 1;

            return (
              <article key={product.id} className="card">
                {/* Main Image */}
                <div className="card-image">
                  {currentImage && (
                    <Image
                      src={currentImage.image_url}
                      alt={product.title}
                      fill
                      sizes="(max-width: 640px) 100vw, (max-width: 1024px) 50vw, 25vw"
                      style={{ objectFit: 'contain' }}
                      priority
                    />
                  )}
                </div>

                {/* Thumbnail Strip */}
                {hasMultipleImages && (
                  <div className="thumbnail-strip">
                    {product.images.map((image) => (
                      <button
                        key={image.id}
                        className={`thumbnail-btn ${currentImage?.id === image.id ? 'active' : ''}`}
                        onClick={() => handleImageSelect(product.id, image)}
                        aria-label={`View ${image.is_primary ? 'primary' : ''} image`}
                      >
                        <Image
                          src={image.image_url}
                          alt={`${product.title} - thumbnail`}
                          fill
                          sizes="60px"
                          style={{ objectFit: 'cover' }}
                        />
                        {image.is_primary && (
                          <span className="thumbnail-badge">Primary</span>
                        )}
                      </button>
                    ))}
                  </div>
                )}

                {/* Product Info */}
                <div className="card-content">
                  <h2 className="card-title">{product.title}</h2>
                  <p className="card-price">{product.price}</p>
                  {hasMultipleImages && (
                    <span className="image-count">{product.images.length} images</span>
                  )}
                </div>
              </article>
            );
          })}
        </div>
      )}
    </main>
  );
}